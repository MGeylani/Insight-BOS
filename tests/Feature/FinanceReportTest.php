<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\ServiceCategory;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Finance\FinanceReports;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\NumberSequenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FinanceReportTest extends TestCase
{
    use RefreshDatabase;

    private User $fin;

    private User $approver;

    private Client $client;

    private Project $project;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, NumberSequenceSeeder::class, ExpenseCategorySeeder::class]);
        ExchangeRate::create(['currency_code' => 'TRY', 'date' => today()->startOfYear(), 'rate' => 40]);
        $perms = array_fill_keys(['finance.view', 'finance.create', 'finance.edit', 'finance.delete', 'finance.approve', 'finance.expense', 'finance.export', 'projects.view', 'crm.view'], 'all');
        $this->fin = $this->userWith($perms);
        $this->approver = $this->userWith($perms);
        $this->client = Client::factory()->create();
        $this->project = Project::factory()->create(['client_id' => $this->client->id]);
        $this->bank = Account::create(['name' => 'بنك', 'type' => 'bank', 'currency' => 'USD', 'opening_balance' => 1000, 'opening_date' => today()->startOfYear()]);
        $this->seedActivity();
    }

    /** Revenue 1000 (design 750 +10% tax, video 250) on the project = 1075 invoiced, 600 collected; expenses: 200 direct (project) + 100 overhead; 2h of labor at $30. */
    private function seedActivity(): void
    {
        $design = ServiceCategory::create(['name' => 'تصميم', 'tax_rate' => 10]);
        $video = ServiceCategory::create(['name' => 'فيديو', 'tax_rate' => 0]);
        $this->actingAs($this->fin)->post('/finance/invoices', [
            'client_id' => $this->client->id, 'project_id' => $this->project->id, 'title' => 'حملة', 'currency' => 'USD',
            'items' => [
                ['name' => 'تصاميم', 'quantity' => 1, 'unit_price' => 750, 'service_category_id' => $design->id],
                ['name' => 'ريل', 'quantity' => 1, 'unit_price' => 250, 'service_category_id' => $video->id],
            ],
        ])->assertSessionHasNoErrors();
        $inv = Invoice::first();
        $this->post("/finance/invoices/{$inv->id}/issue")->assertSessionHasNoErrors();
        $this->post('/finance/payments', ['client_id' => $this->client->id, 'account_id' => $this->bank->id, 'date' => today()->toDateString(), 'amount' => 600, 'method' => 'transfer',
            'allocations' => [['invoice_id' => $inv->id, 'amount' => 600]]])->assertSessionHasNoErrors();

        $direct = ExpenseCategory::where('name', 'طباعة')->first();
        $overhead = ExpenseCategory::where('name', 'إيجار ومرافق')->first();
        foreach ([[$direct, 200, $this->project->id], [$overhead, 100, null]] as [$cat, $amount, $project]) {
            $this->actingAs($this->fin)->post('/finance/expenses', [
                'date' => today()->toDateString(), 'category_id' => $cat->id, 'description' => 'x', 'amount' => $amount, 'currency' => 'USD',
                'paid_by' => 'account', 'account_id' => $this->bank->id, 'project_id' => $project,
            ])->assertSessionHasNoErrors();
            $e = Expense::latest('id')->first();
            Attachment::forceCreate(['attachable_type' => 'expense', 'attachable_id' => $e->id, 'name' => 'r', 'size' => 1, 'status' => 'uploaded']);
            $this->actingAs($this->approver)->post("/finance/expenses/{$e->id}/approve")->assertSessionHasNoErrors();
        }
        TimeEntry::create(['user_id' => $this->fin->id, 'project_id' => $this->project->id, 'client_id' => $this->client->id, 'started_at' => now()->startOfDay()->addHours(9),
            'ended_at' => now()->startOfDay()->addHours(11), 'minutes' => 120, 'billable' => true, 'cost_rate' => 30, 'source' => 'manual']);
    }

    private function range(): array
    {
        return [today()->startOfYear()->toDateString(), today()->toDateString()];
    }

    public function test_profit_and_loss(): void
    {
        $r = FinanceReports::pnl(...$this->range());
        $m = today()->format('Y-m');
        $this->assertSame(1000.0, $r['totals']['revenue']['total']); // net of tax
        $this->assertSame(750.0, collect($r['revenue'])->firstWhere('label', 'تصميم')['total']);
        $this->assertSame(200.0, $r['totals']['direct']['total']);
        $this->assertSame(800.0, $r['totals']['gross']['values'][$m]);
        $this->assertSame(100.0, $r['totals']['overhead']['total']);
        $this->assertSame(700.0, $r['totals']['net']['total']);
        $this->assertSame(60.0, $r['totals']['labor']['total']); // information only
    }

    public function test_cash_flow_ignores_internal_transfers(): void
    {
        $try = Account::create(['name' => 'صندوق', 'type' => 'cash', 'currency' => 'TRY']);
        $this->actingAs($this->fin)->post('/finance/transfers', ['from_account_id' => $this->bank->id, 'to_account_id' => $try->id, 'date' => today()->toDateString(), 'amount' => 100, 'to_amount' => 4000])->assertSessionHasNoErrors();

        $r = FinanceReports::cashflow(...$this->range());
        $this->assertSame(1000.0, $r['opening']);
        $this->assertSame(600.0, $r['totals']['in']['total']);
        $this->assertSame(300.0, $r['totals']['out']['total']);
        $this->assertSame(1300.0, $r['closing']); // 1200 in the bank + 4000 TRY = 100 USD
        $this->assertNull(collect($r['out'])->firstWhere('kind', 'transfer_out'));

        $one = FinanceReports::cashflow(...[...$this->range(), $this->bank->id]);
        $this->assertSame(100.0, collect($one['out'])->firstWhere('kind', 'transfer_out')['total']);
        $this->assertSame(1200.0, $one['closing']); // 1000 + 600 − 300 − 100
    }

    public function test_profitability_by_project_client_service_employee(): void
    {
        [$from, $to] = $this->range();
        $p = FinanceReports::profitability('project', $from, $to);
        $this->assertCount(1, $p['rows']);
        $this->assertSame(1000.0, $p['rows'][0]['revenue']);
        $this->assertSame(200.0, $p['rows'][0]['expenses']);
        $this->assertSame(60.0, $p['rows'][0]['labor']);
        $this->assertSame(740.0, $p['rows'][0]['profit']);

        $c = FinanceReports::profitability('client', $from, $to);
        $this->assertSame(740.0, $c['rows'][0]['profit']); // the overhead expense has no client

        $s = collect(FinanceReports::profitability('service', $from, $to)['rows'])->keyBy('label');
        $this->assertSame(750.0, $s['تصميم']['revenue']);
        $this->assertSame(150.0, $s['تصميم']['expenses']); // 200 × 75%
        $this->assertSame(45.0, $s['تصميم']['labor']);
        $this->assertSame(50.0, $s['فيديو']['expenses']);

        $e = FinanceReports::profitability('employee', $from, $to)['rows'][0];
        $this->assertSame($this->fin->id, $e['id']);
        $this->assertSame(1000.0, $e['revenue']); // all the project's hours are theirs
        $this->assertSame(120, $e['billable_minutes']);
    }

    public function test_pages_exports_and_permissions(): void
    {
        $this->actingAs($this->fin)->get('/finance')->assertOk()->assertInertia(fn (Assert $a) => $a->component('finance/Dashboard')
            ->where('data.cash.total', 1300)->where('data.receivables', 475)->where('data.month.net', 700));
        foreach (['pnl', 'cashflow', 'profit'] as $type) {
            $this->get("/finance/reports?type=$type&group=service")->assertOk()->assertInertia(fn (Assert $a) => $a->component('finance/reports/Index')->where('params.type', $type));
            $this->get("/finance/reports/xlsx?type=$type&group=employee")->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }

        $noExport = $this->userWith(['finance.view' => 'all']);
        $this->actingAs($noExport)->get('/finance/reports')->assertOk();
        $this->get('/finance/reports/xlsx')->assertForbidden();

        $own = $this->userWith(['finance.view' => 'own']);
        $this->actingAs($own)->get('/finance')->assertRedirect('/finance/invoices');
        $this->get('/finance/reports')->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Budget;
use App\Models\Client;
use App\Models\CostCenter;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Finance\Budgets;
use App\Services\Finance\Profitability;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\NumberSequenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BudgetTest extends TestCase
{
    use RefreshDatabase;

    private ExpenseCategory $print;

    private ExpenseCategory $ads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, NumberSequenceSeeder::class, ExpenseCategorySeeder::class]);
        $this->print = ExpenseCategory::where('name', 'طباعة')->first();
        $this->ads = ExpenseCategory::where('name', 'إعلانات ممولة')->first();
    }

    private function finance(): User
    {
        return $this->userWith(array_fill_keys(['finance.view', 'finance.create', 'finance.edit', 'finance.delete', 'finance.approve', 'finance.expense',
            'projects.view', 'projects.manage_budget', 'crm.view'], 'all'));
    }

    /** An approved expense, created directly. */
    private function expense(User $by, array $attrs): Expense
    {
        $this->actingAs($by)->post('/finance/expenses', $attrs + [
            'date' => today()->toDateString(), 'category_id' => $this->print->id, 'description' => 'x', 'amount' => 100, 'currency' => 'USD', 'paid_by' => 'employee',
        ])->assertSessionHasNoErrors();
        $e = Expense::latest('id')->first();
        Attachment::forceCreate(['attachable_type' => 'expense', 'attachable_id' => $e->id, 'name' => 'r.jpg', 'size' => 1, 'status' => 'uploaded']);

        return $e;
    }

    private function approve(User $approver, Expense $e): void
    {
        $this->actingAs($approver)->post("/finance/expenses/{$e->id}/approve")->assertSessionHasNoErrors();
    }

    public function test_project_budget_actuals_alerts_and_labor(): void
    {
        $fin = $this->finance();
        $approver = $this->finance();
        $pm = $this->userWith(['projects.view' => 'own', 'projects.manage_budget' => 'own', 'finance.expense' => 'own']);
        $project = Project::factory()->create(['manager_id' => $pm->id]);

        $this->actingAs($fin)->post('/finance/budgets', [
            'name' => 'ميزانية الحملة', 'project_id' => $project->id, 'period_start' => today()->startOfMonth()->toDateString(),
            'period_end' => today()->endOfMonth()->toDateString(), 'alert_percent' => 80,
            'lines' => [['kind' => 'category', 'category_id' => $this->print->id, 'planned' => 500], ['kind' => 'labor', 'planned' => 200]],
        ])->assertSessionHasNoErrors();
        $b = Budget::first();
        $this->assertSame($fin->id, $b->owner_id);

        // Duplicate category lines are refused.
        $this->post('/finance/budgets', ['name' => 'x', 'period_start' => today()->toDateString(), 'period_end' => today()->toDateString(), 'alert_percent' => 90,
            'lines' => [['kind' => 'category', 'category_id' => $this->print->id, 'planned' => 1], ['kind' => 'category', 'category_id' => $this->print->id, 'planned' => 1]]])->assertSessionHasErrors('lines');

        $e1 = $this->expense($fin, ['project_id' => $project->id, 'amount' => 300]);
        $this->approve($approver, $e1);
        $this->assertSame(0, $fin->notifications()->where('data->kind', 'finance.budget_alert')->count()); // 60%

        $e2 = $this->expense($fin, ['project_id' => $project->id, 'amount' => 150]);
        $this->approve($approver, $e2); // 90% ≥ 80 → warn
        $this->assertSame(1, $fin->notifications()->where('data->kind', 'finance.budget_alert')->count());
        $this->assertSame(1, $pm->notifications()->where('data->kind', 'finance.budget_alert')->count());

        $e3 = $this->expense($fin, ['project_id' => $project->id, 'amount' => 10]);
        $this->approve($approver, $e3); // 92%: same level, no new alert
        $this->assertSame(1, $fin->notifications()->where('data->kind', 'finance.budget_alert')->count());

        $e4 = $this->expense($fin, ['project_id' => $project->id, 'amount' => 100, 'category_id' => $this->ads->id]);
        $this->approve($approver, $e4); // not in the budget: unbudgeted, no alert
        $e5 = $this->expense($fin, ['project_id' => $project->id, 'amount' => 100]);
        $this->approve($approver, $e5); // 112% → over
        $this->assertSame(2, $fin->notifications()->where('data->kind', 'finance.budget_alert')->count());

        // Pending and other projects' expenses do not count.
        $this->expense($fin, ['project_id' => $project->id, 'amount' => 999]);
        $this->approve($approver, $this->expense($fin, ['amount' => 999]));

        TimeEntry::create(['user_id' => $pm->id, 'project_id' => $project->id, 'started_at' => now()->startOfDay()->addHours(9), 'ended_at' => now()->startOfDay()->addHours(13), 'minutes' => 240, 'cost_rate' => 25, 'source' => 'manual']);
        $this->assertSame(0, Budgets::checkAll()); // labor 100/200 = 50%: below the alert; print already alerted at 100%
        $r = Budgets::report($b->fresh());
        $this->assertSame(560.0, $r['lines'][0]['actual']);
        $this->assertSame(100.0, $r['lines'][1]['actual']);
        $this->assertSame(100.0, $r['unbudgeted']);

        $this->get("/finance/budgets/{$b->id}")->assertOk()->assertInertia(fn (Assert $a) => $a->component('finance/budgets/Show')->where('report.planned', 700)->has('expenses', 5));
        $this->get('/finance/budgets')->assertOk()->assertInertia(fn (Assert $a) => $a->has('budgets', 1));

        // The PM sees and manages their project's budget, not company-wide ones.
        $this->actingAs($pm)->get("/finance/budgets/{$b->id}")->assertOk();
        $this->get("/finance/budgets/{$b->id}/edit")->assertOk();
        $this->post('/finance/budgets', ['name' => 'شركة', 'period_start' => today()->toDateString(), 'period_end' => today()->toDateString(), 'alert_percent' => 90,
            'lines' => [['kind' => 'labor', 'planned' => 1]]])->assertForbidden();
        $other = Project::factory()->create();
        $this->post('/finance/budgets', ['name' => 'x', 'project_id' => $other->id, 'period_start' => today()->toDateString(), 'period_end' => today()->toDateString(), 'alert_percent' => 90,
            'lines' => [['kind' => 'labor', 'planned' => 1]]])->assertForbidden();
    }

    public function test_cost_center_and_company_budgets(): void
    {
        $fin = $this->finance();
        $approver = $this->finance();
        $this->actingAs($fin)->post('/finance/cost-centers', ['name' => 'الإدارة'])->assertSessionHasNoErrors();
        $cc = CostCenter::first();
        $project = Project::factory()->create();
        $base = ['period_start' => today()->startOfYear()->toDateString(), 'period_end' => today()->endOfYear()->toDateString(), 'alert_percent' => 90,
            'lines' => [['kind' => 'category', 'category_id' => $this->print->id, 'planned' => 1000]]];
        $this->post('/finance/budgets', $base + ['name' => 'خطأ', 'project_id' => $project->id, 'cost_center_id' => $cc->id])->assertSessionHasErrors('cost_center_id');
        $this->post('/finance/budgets', $base + ['name' => 'الإدارة', 'cost_center_id' => $cc->id])->assertSessionHasNoErrors();
        $this->post('/finance/budgets', $base + ['name' => 'الشركة'])->assertSessionHasNoErrors();
        [$center, $company] = Budget::orderBy('id')->get()->all();

        $this->approve($approver, $this->expense($fin, ['cost_center_id' => $cc->id, 'amount' => 200]));
        $this->approve($approver, $this->expense($fin, ['project_id' => $project->id, 'amount' => 50]));
        $this->assertSame(200.0, Budgets::report($center)['actual']);
        $this->assertSame(250.0, Budgets::report($company)['actual']);
        $this->get('/finance/budgets?scope=center')->assertInertia(fn (Assert $a) => $a->has('budgets', 1));
    }

    public function test_project_profitability(): void
    {
        $fin = $this->finance();
        $approver = $this->finance();
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id, 'budget' => 2000, 'currency' => 'USD']);
        $acc = Account::create(['name' => 'بنك', 'type' => 'bank', 'currency' => 'USD']);
        $cat = \App\Models\ServiceCategory::create(['name' => 'تصميم', 'tax_rate' => 10]);

        $this->actingAs($fin)->post('/finance/invoices', [
            'client_id' => $client->id, 'project_id' => $project->id, 'title' => 'دفعة', 'currency' => 'USD',
            'items' => [['name' => 'تصميم', 'quantity' => 1, 'unit_price' => 1000, 'service_category_id' => $cat->id]],
        ])->assertSessionHasNoErrors();
        $inv = Invoice::first();
        $this->post("/finance/invoices/{$inv->id}/issue")->assertSessionHasNoErrors();
        $this->post('/finance/payments', ['client_id' => $client->id, 'account_id' => $acc->id, 'date' => today()->toDateString(), 'amount' => 600, 'method' => 'cash',
            'allocations' => [['invoice_id' => $inv->id, 'amount' => 600]]])->assertSessionHasNoErrors();
        $this->approve($approver, $this->expense($fin, ['project_id' => $project->id, 'amount' => 300]));
        TimeEntry::create(['user_id' => $fin->id, 'project_id' => $project->id, 'started_at' => now()->subHours(3), 'ended_at' => now()->subHour(), 'minutes' => 120, 'cost_rate' => 50, 'source' => 'manual']);

        $p = Profitability::project($project);
        $this->assertSame(1000.0, $p['revenue']); // net of the 10% tax
        $this->assertSame(1100.0, $p['invoiced']);
        $this->assertSame(600.0, $p['collected']);
        $this->assertSame(500.0, $p['outstanding']);
        $this->assertSame(300.0, $p['expenses']);
        $this->assertSame(100.0, $p['labor']);
        $this->assertSame(600.0, $p['profit']);
        $this->assertSame(60.0, $p['margin']);

        $this->get("/projects/{$project->id}")->assertOk()->assertInertia(fn (Assert $a) => $a->where('profit.profit', 600)->where('can.manage_budgets', true));
        // Without budget visibility, no profit.
        $member = $this->userWith(['projects.view' => 'all']);
        $this->actingAs($member)->get("/projects/{$project->id}")->assertInertia(fn (Assert $a) => $a->where('profit', null));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Quote;
use App\Models\ServiceCategory;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Finance\Invoices;
use App\Services\Tasks\Timesheets;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\NumberSequenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, NumberSequenceSeeder::class]);
    }

    private function finance(string $scope = 'all'): User
    {
        return $this->userWith([
            'finance.view' => $scope, 'finance.create' => $scope, 'finance.edit' => $scope, 'finance.delete' => $scope,
            'crm.view' => 'all', 'crm.create' => 'all', 'crm.edit' => 'all',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Client $client, array $extra = []): array
    {
        return $extra + [
            'client_id' => $client->id,
            'title' => 'تصميم هوية',
            'currency' => 'USD',
            'items' => [
                ['name' => 'شعار', 'quantity' => 1, 'unit_price' => 500, 'discount_percent' => 10],
                ['name' => 'دليل الهوية', 'quantity' => 2, 'unit_price' => 150.5],
            ],
        ];
    }

    /** An issued + signed project contract with two instalments. */
    private function contract(User $u, Client $client): Contract
    {
        $this->actingAs($u)->post('/crm/contracts', [
            'type' => 'project', 'client_id' => $client->id, 'title' => 'حملة', 'currency' => 'USD', 'value' => 2000,
            'start_date' => now()->toDateString(),
            'schedule' => [
                ['kind' => 'payment', 'title' => 'دفعة مقدمة', 'due_date' => now()->toDateString(), 'amount' => 1000],
                ['kind' => 'payment', 'title' => 'الدفعة النهائية', 'due_date' => now()->addMonth()->toDateString(), 'amount' => 1000],
            ],
        ])->assertRedirect();
        $c = Contract::latest('id')->first();
        $this->post("/crm/contracts/{$c->id}/issue")->assertSessionHasNoErrors();
        $this->post("/crm/contracts/{$c->id}/sign", ['signed_on' => now()->toDateString(), 'client_signatory' => 'أحمد'])->assertSessionHasNoErrors();

        return $c->fresh();
    }

    public function test_draft_issue_freezes_number_total_and_due_date(): void
    {
        $u = $this->finance();
        $client = Client::factory()->create();
        $cat = ServiceCategory::create(['name' => 'تصميم', 'tax_rate' => 5]);

        $data = $this->payload($client);
        $data['items'][0]['service_category_id'] = $cat->id;
        $this->actingAs($u)->post('/finance/invoices', $data)->assertRedirect();
        $inv = Invoice::first();
        $this->assertSame('draft', $inv->status);
        $this->assertNull($inv->number);
        // 450 net (+5% tax = 22.5) + 301 = 773.5
        $this->assertSame(773.5, $inv->totals()['total']);

        $this->post("/finance/invoices/{$inv->id}/issue")->assertSessionHasNoErrors();
        $inv->refresh();
        $this->assertSame('issued', $inv->status);
        $this->assertSame('INV-'.now()->year.'-0001', $inv->number);
        $this->assertSame('773.50', $inv->total);
        $this->assertSame(today()->addDays(Invoices::paymentDays())->toDateString(), $inv->due_date->toDateString());
        $this->assertSame('issued', $inv->displayStatus());
        $this->assertSame(773.5, $inv->balance());

        // The category rate changes later: the issued invoice keeps its total.
        $cat->update(['tax_rate' => 50]);
        $this->assertSame(773.5, $inv->fresh()->grandTotal());

        // Issued invoices are locked.
        $this->put("/finance/invoices/{$inv->id}", $this->payload($client))->assertSessionHasErrors('status');
        $this->get("/finance/invoices/{$inv->id}/edit")->assertRedirect("/finance/invoices/{$inv->id}");
        $this->delete("/finance/invoices/{$inv->id}")->assertSessionHasErrors('status');

        // Overdue is derived from the due date.
        $inv->forceFill(['due_date' => today()->subDay()])->save();
        $this->assertSame('overdue', $inv->fresh()->displayStatus());

        $this->get('/finance/invoices')->assertOk()->assertInertia(fn (Assert $p) => $p->component('finance/invoices/Index')
            ->where('summary.overdue_count', 1)->where('invoices.data.0.status', 'overdue'));
        $this->get("/finance/invoices/{$inv->id}")->assertOk()->assertInertia(fn (Assert $p) => $p->where('invoice.number', $inv->number));
    }

    public function test_cancel_requires_reason_and_keeps_number(): void
    {
        $u = $this->finance();
        $client = Client::factory()->create();
        $this->actingAs($u)->post('/finance/invoices', $this->payload($client));
        $inv = Invoice::first();
        $this->post("/finance/invoices/{$inv->id}/cancel", ['reason' => 'x'])->assertSessionHasErrors('status'); // drafts are deleted, not cancelled
        $this->post("/finance/invoices/{$inv->id}/issue");
        $this->post("/finance/invoices/{$inv->id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/finance/invoices/{$inv->id}/cancel", ['reason' => 'خطأ في المبلغ'])->assertSessionHasNoErrors();
        $inv->refresh();
        $this->assertSame('cancelled', $inv->status);
        $this->assertNotNull($inv->number);
        $this->assertSame(0.0, $inv->balance());

        // The next invoice takes the next number (no reuse).
        $this->post('/finance/invoices', $this->payload($client));
        $next = Invoice::latest('id')->first();
        $this->post("/finance/invoices/{$next->id}/issue");
        $this->assertSame('INV-'.now()->year.'-0002', $next->fresh()->number);
    }

    public function test_contract_instalments_are_billed_once_and_released_on_cancel(): void
    {
        $u = $this->finance();
        $client = Client::factory()->create();
        $c = $this->contract($u, $client);
        $first = $c->schedules()->where('kind', 'payment')->orderBy('due_date')->first();

        // The create page pre-fills the next pending instalment.
        $this->get("/finance/invoices/create?contract_id={$c->id}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('form.contract_id', $c->id)->where('form.items.0.contract_schedule_id', $first->id)->where('form.items.0.unit_price', 1000));

        $line = Invoices::scheduleLine($c, $first);
        $this->post('/finance/invoices', $this->payload($client, ['contract_id' => $c->id, 'items' => [$line]]))->assertSessionHasNoErrors();
        $inv = Invoice::first();
        $this->assertSame($inv->id, $first->fresh()->invoice_id);

        // A second invoice cannot claim the same instalment.
        $this->post('/finance/invoices', $this->payload($client, ['items' => [$line]]))->assertSessionHasErrors('items');
        // Another client's invoice cannot claim it either.
        $other = Client::factory()->create();
        $this->post('/finance/invoices', $this->payload($other, ['items' => [$line]]))->assertSessionHasErrors('items');

        // Pending-instalments list skips it.
        $this->getJson("/finance/pending-schedules/{$client->id}")->assertOk()->assertJsonCount(1, 'rows');

        $this->post("/finance/invoices/{$inv->id}/issue");
        $this->post("/finance/invoices/{$inv->id}/cancel", ['reason' => 'استبدال']);
        $this->assertNull($first->fresh()->invoice_id);

        // Deleting a draft frees its instalment too.
        $this->post('/finance/invoices', $this->payload($client, ['items' => [$line]]))->assertSessionHasNoErrors();
        $draft = Invoice::latest('id')->first();
        $this->assertSame($draft->id, $first->fresh()->invoice_id);
        $this->delete("/finance/invoices/{$draft->id}")->assertRedirect('/finance/invoices');
        $this->assertNull($first->fresh()->invoice_id);
    }

    public function test_billable_time_needs_an_approved_week_and_is_billed_once(): void
    {
        $u = $this->finance();
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);
        $worker = User::factory()->create();
        $week = Timesheets::weekStart(now()->subWeeks(2));
        $entry = fn ($at, $min) => TimeEntry::create(['user_id' => $worker->id, 'project_id' => $project->id, 'client_id' => $client->id,
            'started_at' => $at, 'ended_at' => $at->copy()->addMinutes($min), 'minutes' => $min, 'billable' => true, 'source' => 'manual']);
        $a = $entry($week->copy()->addDay()->setTime(9, 0), 120);
        $entry($week->copy()->addWeek()->addDay()->setTime(9, 0), 60); // week not approved
        Timesheet::create(['user_id' => $worker->id, 'week_start' => $week, 'status' => 'approved']);

        $groups = $this->actingAs($u)->getJson("/finance/billable-time/{$project->id}")->assertOk()->json('groups');
        $this->assertCount(1, $groups);
        $this->assertSame(120, $groups[0]['minutes']);
        $this->assertSame([$a->id], $groups[0]['time_ids']);

        $this->post('/finance/invoices', $this->payload($client, ['project_id' => $project->id, 'items' => [
            ['name' => 'ساعات', 'quantity' => 2, 'unit' => 'ساعة', 'unit_price' => 40, 'time_ids' => [$a->id]],
        ]]))->assertSessionHasNoErrors();
        $this->assertNotNull($a->fresh()->invoice_item_id);
        $this->getJson("/finance/billable-time/{$project->id}")->assertJsonCount(0, 'groups');

        // Editing the draft still offers its own time.
        $inv = Invoice::first();
        $this->getJson("/finance/billable-time/{$project->id}?invoice={$inv->id}")->assertJsonCount(1, 'groups');
    }

    public function test_from_quote_requires_accepted(): void
    {
        $u = $this->finance();
        $client = Client::factory()->create();
        $q = Quote::factory()->create(['client_id' => $client->id, 'status' => 'sent']);
        $q->items()->create(['name' => 'بند', 'quantity' => 1, 'unit_price' => 100]);
        $this->actingAs($u)->get("/finance/invoices/create?quote_id={$q->id}")->assertSessionHasErrors('quote_id');
        $q->forceFill(['status' => 'accepted'])->save();
        $this->get("/finance/invoices/create?quote_id={$q->id}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('form.quote_id', $q->id)->where('form.items.0.name', 'بند'));
    }

    public function test_scope_and_permissions(): void
    {
        $mine = $this->finance('own');
        $other = $this->finance('own');
        $client = Client::factory()->create();
        $this->actingAs($other)->post('/finance/invoices', $this->payload($client));
        $theirs = Invoice::first();

        $this->actingAs($mine)->get("/finance/invoices/{$theirs->id}")->assertForbidden();
        $this->actingAs($mine)->get('/finance/invoices')->assertInertia(fn (Assert $p) => $p->has('invoices.data', 0));
        $this->actingAs($other)->get('/finance/invoices')->assertInertia(fn (Assert $p) => $p->has('invoices.data', 1));

        $nobody = $this->userWith(['crm.view' => 'all']);
        $this->actingAs($nobody)->get('/finance/invoices')->assertForbidden();
        // Linked records must belong to the same client.
        $foreign = Project::factory()->create();
        $this->actingAs($other)->post('/finance/invoices', $this->payload($client, ['project_id' => $foreign->id]))->assertSessionHasErrors('project_id');
    }

    public function test_retainer_instalments_get_draft_invoices(): void
    {
        $u = $this->finance();
        $client = Client::factory()->create();
        $this->actingAs($u)->post('/crm/contracts', [
            'type' => 'retainer', 'client_id' => $client->id, 'title' => 'سوشال ميديا', 'currency' => 'USD', 'monthly_fee' => 600,
            'term_months' => 3, 'billing_timing' => 'advance', 'start_date' => today()->addDays(3)->toDateString(),
        ])->assertRedirect();
        $c = Contract::first();
        $this->post("/crm/contracts/{$c->id}/issue");
        $this->post("/crm/contracts/{$c->id}/sign", ['signed_on' => today()->toDateString(), 'client_signatory' => 'أحمد']);

        $this->assertSame(1, Invoices::draftRetainers(7)); // only the first month is due within 7 days
        $this->assertSame(0, Invoices::draftRetainers(7)); // and only once
        $inv = Invoice::first();
        $this->assertSame('draft', $inv->status);
        $this->assertSame($c->id, $inv->contract_id);
        $this->assertSame(600.0, $inv->totals()['total']);
        $this->assertSame(1, $u->fresh()->notifications()->count());
    }

    public function test_pdf_renders(): void
    {
        $u = $this->finance();
        $client = Client::factory()->create();
        $this->actingAs($u)->post('/finance/invoices', $this->payload($client));
        $inv = Invoice::first();
        $this->post("/finance/invoices/{$inv->id}/issue");
        $res = $this->get("/finance/invoices/{$inv->id}/pdf");
        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }
}

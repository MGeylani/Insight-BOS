<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\ExchangeRate;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\User;
use App\Services\Sales\ContractPdf;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\NumberSequenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, NumberSequenceSeeder::class]);
    }

    private function sales(string $scope = 'all'): User
    {
        return $this->userWith(['crm.view' => $scope, 'crm.create' => $scope, 'crm.edit' => $scope, 'crm.delete' => $scope]);
    }

    /** @return array<string, mixed> */
    private function project(Client $client, array $extra = []): array
    {
        return $extra + [
            'type' => 'project',
            'client_id' => $client->id,
            'title' => 'هوية بصرية كاملة',
            'currency' => 'USD',
            'value' => 2000,
            'start_date' => '2026-12-01',
            'scope' => 'شعار ودليل هوية',
            'schedule' => [
                ['kind' => 'payment', 'title' => 'دفعة مقدمة', 'due_date' => '2026-12-01', 'amount' => 1000],
                ['kind' => 'milestone', 'title' => 'تسليم الشعار', 'due_date' => '2026-12-15'],
                ['kind' => 'payment', 'title' => 'الدفعة النهائية', 'due_date' => '2027-01-10', 'amount' => 1000],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function retainer(Client $client, array $extra = []): array
    {
        return $extra + [
            'type' => 'retainer',
            'client_id' => $client->id,
            'title' => 'إدارة منصات التواصل',
            'currency' => 'USD',
            'monthly_fee' => 600,
            'term_months' => 3,
            'billing_timing' => 'advance',
            'auto_renew' => true,
            'notice_days' => 30,
            'start_date' => '2026-01-15',
            'schedule' => [
                ['kind' => 'milestone', 'title' => 'خطة المحتوى الأولى', 'due_date' => '2026-01-20'],
                ['kind' => 'payment', 'title' => 'ignored', 'amount' => 5], // retainer payments are generated, never typed
            ],
        ];
    }

    public function test_project_draft_then_issue_requires_schedule_to_match_value(): void
    {
        $u = $this->sales();
        $client = Client::factory()->create();

        $this->actingAs($u)->post('/crm/contracts', $this->project($client, ['value' => 2500]))->assertRedirect();
        $c = Contract::first();
        $this->assertSame('draft', $c->status);
        $this->assertNull($c->number);
        $this->assertSame($u->id, $c->owner_id);
        $this->assertCount(3, $c->schedules);
        $this->assertSame(2000.0, $c->scheduledTotal());
        $this->assertSame(2500.0, $c->totalValue());

        $this->actingAs($u)->post("/crm/contracts/{$c->id}/issue")->assertSessionHasErrors('schedule');
        $this->assertNull($c->fresh()->number);

        $this->actingAs($u)->put("/crm/contracts/{$c->id}", $this->project($client))->assertRedirect();
        $this->actingAs($u)->post("/crm/contracts/{$c->id}/issue")->assertSessionHasNoErrors();
        $c->refresh();
        $this->assertSame('sent', $c->status);
        $this->assertSame('CTR-'.now()->year.'-0001', $c->number);
        $this->assertSame('1.000000', $c->fx_rate);

        // Issued contracts are locked.
        $this->actingAs($u)->put("/crm/contracts/{$c->id}", $this->project($client))->assertSessionHasErrors('status');
        $this->actingAs($u)->get("/crm/contracts/{$c->id}/edit")->assertRedirect("/crm/contracts/{$c->id}");
        $this->actingAs($u)->delete("/crm/contracts/{$c->id}")->assertStatus(422);

        $this->actingAs($u)->get("/crm/contracts/{$c->id}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('crm/contracts/Show')
                ->where('contract.status', 'sent')->where('contract.total', 2000)->has('contract.schedule', 3)
                ->where('contract.schedule.0.percent', 50));
        $this->actingAs($u)->get('/crm/contracts')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('crm/contracts/Index')->has('contracts.data', 1)->where('summary.awaiting_signature', 1));
    }

    public function test_issue_needs_an_exchange_rate_and_freezes_it(): void
    {
        $u = $this->sales();
        $client = Client::factory()->create();
        $this->actingAs($u)->post('/crm/contracts', $this->project($client, ['currency' => 'TRY']));
        $c = Contract::first();
        $this->actingAs($u)->post("/crm/contracts/{$c->id}/issue")->assertSessionHasErrors('currency');

        ExchangeRate::create(['currency_code' => 'TRY', 'date' => today(), 'rate' => 40]);
        $this->actingAs($u)->post("/crm/contracts/{$c->id}/issue")->assertSessionHasNoErrors();
        $c->refresh();
        $this->assertSame('40.000000', $c->fx_rate);
        $this->assertSame(50.0, $c->totalInBase());
    }

    public function test_sign_wins_opportunity_and_milestones_can_be_completed(): void
    {
        $u = $this->sales();
        $opp = Opportunity::factory()->create(['stage' => 'negotiation', 'probability' => 75]);
        $this->actingAs($u)->post('/crm/contracts', $this->project($opp->client, ['opportunity_id' => $opp->id]));
        $c = Contract::first();
        $milestone = $c->schedules()->where('kind', 'milestone')->first();
        $payment = $c->schedules()->where('kind', 'payment')->first();

        $this->actingAs($u)->post("/crm/contracts/{$c->id}/sign", ['signed_on' => today()->toDateString()])->assertSessionHasErrors('status'); // draft
        $this->actingAs($u)->post("/crm/contracts/{$c->id}/issue");
        $this->actingAs($u)->post("/crm/contracts/{$c->id}/schedule/{$milestone->id}", ['done' => true])->assertSessionHasErrors('row'); // not active yet
        $this->actingAs($u)->post("/crm/contracts/{$c->id}/sign", ['signed_on' => today()->addDay()->toDateString()])->assertSessionHasErrors('signed_on');

        $this->actingAs($u)->post("/crm/contracts/{$c->id}/sign", ['signed_on' => today()->toDateString(), 'client_signatory' => 'سارة'])->assertSessionHasNoErrors();
        $c->refresh();
        $this->assertSame('active', $c->status);
        $this->assertSame('سارة', $c->client_signatory);
        $this->assertSame('won', $opp->fresh()->stage);

        $this->actingAs($u)->post("/crm/contracts/{$c->id}/schedule/{$milestone->id}", ['done' => true])->assertSessionHasNoErrors();
        $this->assertSame('done', $milestone->fresh()->status);
        $this->assertSame($u->id, $milestone->fresh()->done_by);
        $this->actingAs($u)->post("/crm/contracts/{$c->id}/schedule/{$payment->id}", ['done' => true])->assertSessionHasErrors('row');

        $this->actingAs($u)->post("/crm/contracts/{$c->id}/complete")->assertSessionHasNoErrors();
        $this->assertSame('completed', $c->fresh()->status);
        $this->actingAs($u)->post("/crm/contracts/{$c->id}/terminate", ['terminated_on' => today()->toDateString(), 'reason' => 'x'])->assertSessionHasErrors('status');
    }

    public function test_retainer_generates_monthly_payments_renews_and_terminates(): void
    {
        $u = $this->sales();
        $client = Client::factory()->create();
        $this->actingAs($u)->post('/crm/contracts', $this->retainer($client))->assertRedirect();
        $c = Contract::first();

        $this->assertNull($c->value);
        $this->assertSame('2026-04-14', $c->end_date->toDateString());
        $payments = $c->schedules()->where('kind', 'payment')->orderBy('due_date')->get();
        $this->assertCount(3, $payments);
        $this->assertSame(['2026-01-15', '2026-02-15', '2026-03-15'], $payments->map(fn ($p) => $p->due_date->toDateString())->all());
        $this->assertSame('2026-02-14', $payments[0]->period_end->toDateString());
        $this->assertSame('600.00', $payments[0]->amount);
        $this->assertSame(1800.0, $c->totalValue());
        $this->assertSame(1, $c->schedules()->where('kind', 'milestone')->count());

        // Arrears: due on the last day of each period.
        $this->actingAs($u)->put("/crm/contracts/{$c->id}", $this->retainer($client, ['billing_timing' => 'arrears']));
        $this->assertSame('2026-02-14', $c->schedules()->where('kind', 'payment')->orderBy('due_date')->first()->due_date->toDateString());
        $this->actingAs($u)->put("/crm/contracts/{$c->id}", $this->retainer($client));

        $this->actingAs($u)->post("/crm/contracts/{$c->id}/issue")->assertSessionHasNoErrors();
        $this->actingAs($u)->post("/crm/contracts/{$c->id}/renew")->assertSessionHasErrors('status'); // not signed yet
        $this->actingAs($u)->post("/crm/contracts/{$c->id}/sign", ['signed_on' => '2026-01-10']);
        $c->refresh();
        $this->assertSame('expired', $c->displayStatus()); // its term ended in April 2026

        $this->actingAs($u)->post("/crm/contracts/{$c->id}/renew")->assertSessionHasNoErrors();
        $c->refresh();
        $this->assertSame('2026-07-14', $c->end_date->toDateString());
        $this->assertSame(6, $c->schedules()->where('kind', 'payment')->count());
        $this->assertSame('2026-06-15', $c->schedules()->where('kind', 'payment')->reorder()->orderByDesc('due_date')->first()->due_date->toDateString());
        $this->assertSame(3600.0, $c->totalValue());

        $this->actingAs($u)->post("/crm/contracts/{$c->id}/terminate", ['terminated_on' => '2026-05-20', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($u)->post("/crm/contracts/{$c->id}/terminate", ['terminated_on' => '2026-05-20', 'reason' => 'إيقاف الحملة'])->assertSessionHasNoErrors();
        $c->refresh();
        $this->assertSame('terminated', $c->status);
        $this->assertSame(1, $c->schedules()->where('kind', 'payment')->where('status', 'cancelled')->count());
        $this->assertSame(3000.0, $c->totalValue());
    }

    public function test_from_quote_prefill_cancel_and_duplicate(): void
    {
        $u = $this->sales();
        $quote = Quote::factory()->create(['status' => 'accepted', 'number' => 'QT-2026-0007']);
        $quote->items()->create(['name' => 'تصميم شعار', 'details' => 'ثلاثة مقترحات', 'quantity' => 1, 'unit_price' => 900]);

        $this->actingAs($u)->get("/crm/contracts/create?quote_id={$quote->id}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('crm/contracts/Edit')
                ->where('form.quote_id', $quote->id)->where('form.client_id', $quote->client_id)->where('form.value', 900)
                ->where('form.scope', '• تصميم شعار: ثلاثة مقترحات')->has('form.schedule', 1));

        $this->actingAs($u)->post('/crm/contracts', $this->project($quote->client, ['quote_id' => $quote->id, 'value' => 2000]))->assertRedirect();
        $c = Contract::first();
        $this->assertSame($quote->id, $c->quote_id);
        $this->actingAs($u)->get("/crm/quotes/{$quote->id}")->assertInertia(fn (Assert $p) => $p->has('contracts', 1));

        $this->actingAs($u)->post("/crm/contracts/{$c->id}/cancel")->assertSessionHasErrors('status'); // draft
        $this->actingAs($u)->post("/crm/contracts/{$c->id}/issue");
        $this->actingAs($u)->post("/crm/contracts/{$c->id}/cancel")->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $c->fresh()->status);
        $this->assertNotNull($c->fresh()->number);

        $this->actingAs($u)->post("/crm/contracts/{$c->id}/duplicate")->assertRedirect();
        $copy = Contract::latest('id')->first();
        $this->assertNotSame($c->id, $copy->id);
        $this->assertSame('draft', $copy->status);
        $this->assertNull($copy->number);
        $this->assertSame($c->id, $copy->source_contract_id);
        $this->assertCount(3, $copy->schedules);
        $this->assertSame(2000.0, $copy->scheduledTotal());

        $this->actingAs($u)->delete("/crm/contracts/{$copy->id}")->assertRedirect('/crm/contracts');
        $this->assertSoftDeleted($copy);
    }

    public function test_own_scope_and_links_must_match_the_client(): void
    {
        $me = $this->sales('own');
        $mine = Client::factory()->create(['owner_id' => $me->id]);
        $foreign = Client::factory()->create();
        $theirs = Contract::factory()->create(['client_id' => $foreign->id]);

        $this->actingAs($me)->get("/crm/contracts/{$theirs->id}")->assertForbidden();
        $this->actingAs($me)->get("/crm/contracts/{$theirs->id}/pdf")->assertForbidden();
        $this->actingAs($me)->post('/crm/contracts', $this->project($foreign))->assertForbidden();

        $otherQuote = Quote::factory()->create(['client_id' => $foreign->id]);
        $this->actingAs($me)->post('/crm/contracts', $this->project($mine, ['quote_id' => $otherQuote->id]))->assertSessionHasErrors('quote_id');
        $otherOpp = Opportunity::factory()->create(['client_id' => $foreign->id]);
        $this->actingAs($me)->post('/crm/contracts', $this->project($mine, ['opportunity_id' => $otherOpp->id]))->assertSessionHasErrors('opportunity_id');
        $this->actingAs($me)->post('/crm/contracts', $this->project($mine, ['schedule' => [['kind' => 'payment', 'title' => 'x']]]))
            ->assertSessionHasErrors('schedule.0.amount');

        $this->actingAs($me)->post('/crm/contracts', $this->project($mine))->assertRedirect();
        $this->actingAs($me)->get('/crm/contracts')->assertInertia(fn (Assert $p) => $p->has('contracts.data', 1)->where('contracts.data.0.client.id', $mine->id));
        $this->actingAs($me)->get("/crm/clients/{$mine->id}")->assertInertia(fn (Assert $p) => $p->has('contracts', 1));

        $this->actingAs($this->userWith())->get('/crm/contracts')->assertForbidden();
    }

    public function test_pdf_and_default_terms(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put('/settings/contract-defaults', ['terms_project' => 'شروط المشاريع', 'terms_retainer' => 'شروط الشهري'])->assertRedirect();
        $client = Client::factory()->create(['name' => 'منبر منظمات المجتمع المدني']);
        $this->actingAs($admin)->get("/crm/contracts/create?client_id={$client->id}&type=retainer")
            ->assertInertia(fn (Assert $p) => $p->where('form.type', 'retainer')->where('form.terms', 'شروط الشهري'));

        $this->actingAs($admin)->post('/crm/contracts', $this->retainer($client, ['terms' => 'شروط الشهري']));
        $c = Contract::first();
        $res = $this->actingAs($admin)->get("/crm/contracts/{$c->id}/pdf");
        $res->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertStringContainsString('inline; filename="contract-draft-'.$c->id.'.pdf"', $res->headers->get('Content-Disposition'));

        $this->actingAs($admin)->post("/crm/contracts/{$c->id}/issue");
        $c->refresh();
        $res = $this->actingAs($admin)->get("/crm/contracts/{$c->id}/pdf?download=1");
        $res->assertOk();
        $this->assertStringContainsString('attachment; filename="'.$c->number.'.pdf"', $res->headers->get('Content-Disposition'));

        $data = ContractPdf::data($c);
        $this->assertFileExists($data['logo']);
        $this->assertCount(3, $data['payments']);
        $this->assertCount(1, $data['milestones']);
        $this->assertSame(1800.0, $data['payments_total']);
    }
}

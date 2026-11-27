<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ExchangeRate;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Sales\QuotePdf;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\NumberSequenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QuoteTest extends TestCase
{
    use RefreshDatabase;

    private ServiceCategory $design;

    private ServiceCategory $taxed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, NumberSequenceSeeder::class]);
        $this->design = ServiceCategory::create(['name' => 'التصميم', 'tax_rate' => 0]);
        $this->taxed = ServiceCategory::create(['name' => 'الطباعة', 'tax_rate' => 10]);
    }

    private function sales(string $scope = 'all'): User
    {
        return $this->userWith(['crm.view' => $scope, 'crm.create' => $scope, 'crm.edit' => $scope, 'crm.delete' => $scope]);
    }

    /** @return array<string, mixed> */
    private function payload(Client $client, array $extra = []): array
    {
        return $extra + [
            'client_id' => $client->id,
            'title' => 'حزمة الهوية',
            'currency' => 'USD',
            'valid_until' => now()->addDays(15)->toDateString(),
            'items' => [
                ['name' => 'تصميم شعار', 'quantity' => 1, 'unit_price' => 1000, 'discount_percent' => 10, 'service_category_id' => $this->design->id],
                ['name' => 'طباعة رول أب', 'quantity' => 2, 'unit_price' => 150, 'service_category_id' => $this->taxed->id],
            ],
        ];
    }

    public function test_service_catalog_view_and_manage_permissions(): void
    {
        $viewer = $this->userWith(['crm.view' => 'all']);
        $this->actingAs($viewer)->get('/crm/services')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('crm/services/Index')->where('canManage', false));
        $this->actingAs($viewer)->post('/crm/services', ['name' => 'x'])->assertForbidden();

        $manager = $this->userWith(['crm.view' => 'all', 'settings.manage' => 'all']);
        $this->actingAs($manager)->post('/crm/services', [
            'service_category_id' => $this->design->id, 'name' => 'إدارة حساب إنستغرام', 'unit' => 'شهر',
            'price' => 600, 'currency' => 'USD', 'is_active' => true,
        ])->assertRedirect();
        $service = Service::firstWhere('name', 'إدارة حساب إنستغرام');
        $this->assertSame('600.00', $service->price);

        $this->actingAs($manager)->delete("/crm/services/{$service->id}")->assertRedirect();
        $this->assertSoftDeleted($service);
    }

    public function test_create_draft_computes_totals_and_tax_from_category(): void
    {
        $u = $this->sales();
        $client = Client::factory()->create();

        $this->actingAs($u)->post('/crm/quotes', $this->payload($client))->assertRedirect();

        $q = Quote::firstWhere('title', 'حزمة الهوية');
        $this->assertSame('draft', $q->status);
        $this->assertNull($q->number);
        $this->assertSame($u->id, $q->owner_id);
        $this->assertCount(2, $q->items);
        $this->assertSame('10.00', $q->items[1]->tax_rate);
        // 1000 - 10% = 900; 2 x 150 = 300 (+10% tax = 30)
        $this->assertSame(['subtotal' => 1300.0, 'discount' => 100.0, 'net' => 1200.0, 'tax' => 30.0, 'total' => 1230.0], $q->totals());

        $this->actingAs($u)->get("/crm/quotes/{$q->id}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('crm/quotes/Show')->where('quote.totals.total', 1230)->where('quote.status', 'draft'));
        $this->actingAs($u)->get('/crm/quotes')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('crm/quotes/Index')->has('quotes.data', 1));
        $this->actingAs($u)->get("/crm/quotes/create?client_id={$client->id}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('crm/quotes/Edit')->where('form.client_id', $client->id));
    }

    public function test_issue_assigns_number_freezes_rate_and_moves_opportunity(): void
    {
        $u = $this->sales();
        $opp = Opportunity::factory()->create(['stage' => 'proposal', 'probability' => 30, 'amount' => 0, 'currency' => 'TRY']);
        ExchangeRate::create(['currency_code' => 'TRY', 'date' => today(), 'rate' => 40]);

        $this->actingAs($u)->post('/crm/quotes', $this->payload($opp->client, ['opportunity_id' => $opp->id, 'currency' => 'TRY']))->assertRedirect();
        $q = Quote::first();

        // A category rate change before issue applies; after issue it does not.
        $this->taxed->update(['tax_rate' => 20]);
        $this->actingAs($u)->post("/crm/quotes/{$q->id}/issue")->assertRedirect()->assertSessionHasNoErrors();
        $q->refresh();
        $this->assertSame('sent', $q->status);
        $this->assertSame('QT-'.now()->year.'-0001', $q->number);
        $this->assertSame('40.000000', $q->fx_rate);
        $this->assertSame('20.00', $q->items()->get()[1]->tax_rate);
        $this->assertSame(1260.0, $q->totals()['total']);
        $this->assertSame(31.5, $q->totalInBase());

        $this->taxed->update(['tax_rate' => 0]);
        $this->assertSame('20.00', $q->items()->get()[1]->tax_rate);

        $opp->refresh();
        $this->assertSame('sent', $opp->stage);
        $this->assertSame('1260.00', $opp->amount);

        // Issued quotes are locked.
        $this->actingAs($u)->put("/crm/quotes/{$q->id}", $this->payload($opp->client))->assertSessionHasErrors('status');
        $this->actingAs($u)->get("/crm/quotes/{$q->id}/edit")->assertRedirect("/crm/quotes/{$q->id}");
        $this->actingAs($u)->delete("/crm/quotes/{$q->id}")->assertStatus(422);
        $this->actingAs($u)->post("/crm/quotes/{$q->id}/issue")->assertSessionHasErrors('status');
    }

    public function test_issue_needs_items_and_an_exchange_rate(): void
    {
        $u = $this->sales();
        $client = Client::factory()->create();
        $this->actingAs($u)->post('/crm/quotes', $this->payload($client, ['items' => []]));
        $empty = Quote::first();
        $this->actingAs($u)->post("/crm/quotes/{$empty->id}/issue")->assertSessionHasErrors('items');

        $this->actingAs($u)->post('/crm/quotes', $this->payload($client, ['currency' => 'SYP', 'title' => 'syp']));
        $syp = Quote::firstWhere('title', 'syp');
        $this->actingAs($u)->post("/crm/quotes/{$syp->id}/issue")->assertSessionHasErrors('currency');
        $this->assertNull($syp->fresh()->number);
    }

    public function test_accept_wins_opportunity_and_reject_needs_reason(): void
    {
        $u = $this->sales();
        $opp = Opportunity::factory()->create(['stage' => 'negotiation', 'probability' => 75]);
        $this->actingAs($u)->post('/crm/quotes', $this->payload($opp->client, ['opportunity_id' => $opp->id]));
        $q = Quote::first();
        $this->actingAs($u)->post("/crm/quotes/{$q->id}/accept")->assertSessionHasErrors('status'); // still a draft
        $this->actingAs($u)->post("/crm/quotes/{$q->id}/issue");
        $this->assertSame('negotiation', $opp->fresh()->stage); // later stages are not moved back

        $this->actingAs($u)->post("/crm/quotes/{$q->id}/accept")->assertSessionHasNoErrors();
        $this->assertSame('accepted', $q->fresh()->status);
        $this->assertSame('won', $opp->fresh()->stage);
        $this->actingAs($u)->post("/crm/quotes/{$q->id}/cancel")->assertSessionHasErrors('status');

        $other = Quote::factory()->create(['client_id' => $opp->client_id]);
        $other->items()->create(['name' => 'x', 'quantity' => 1, 'unit_price' => 10]);
        $this->actingAs($u)->post("/crm/quotes/{$other->id}/issue");
        $this->actingAs($u)->post("/crm/quotes/{$other->id}/reject", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($u)->post("/crm/quotes/{$other->id}/reject", ['reason' => 'السعر مرتفع'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $other->fresh()->status);
        $this->actingAs($u)->post("/crm/quotes/{$other->id}/cancel")->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $other->fresh()->status);
        $this->assertNotNull($other->fresh()->number);
    }

    public function test_expired_is_derived_and_duplicate_creates_a_draft(): void
    {
        $u = $this->sales();
        $client = Client::factory()->create();
        $this->actingAs($u)->post('/crm/quotes', $this->payload($client));
        $q = Quote::first();
        $this->actingAs($u)->post("/crm/quotes/{$q->id}/issue");
        $q->forceFill(['valid_until' => today()->subDay()])->save();
        $this->assertSame('expired', $q->fresh()->displayStatus());
        $this->actingAs($u)->get('/crm/quotes?status=expired')->assertInertia(fn (Assert $p) => $p->has('quotes.data', 1));
        $this->actingAs($u)->get('/crm/quotes?status=sent')->assertInertia(fn (Assert $p) => $p->has('quotes.data', 0));

        $this->actingAs($u)->post("/crm/quotes/{$q->id}/duplicate")->assertRedirect();
        $copy = Quote::latest('id')->first();
        $this->assertNotSame($q->id, $copy->id);
        $this->assertSame('draft', $copy->status);
        $this->assertNull($copy->number);
        $this->assertSame($q->id, $copy->source_quote_id);
        $this->assertCount(2, $copy->items);
        $this->assertSame($q->totals()['total'], $copy->totals()['total']);

        $this->actingAs($u)->delete("/crm/quotes/{$copy->id}")->assertRedirect('/crm/quotes');
        $this->assertSoftDeleted($copy);
    }

    public function test_own_scope_only_sees_own_quotes_and_opportunity_must_match_client(): void
    {
        $me = $this->sales('own');
        $mine = Client::factory()->create(['owner_id' => $me->id]);
        $foreign = Client::factory()->create();
        $theirs = Quote::factory()->create(['client_id' => $foreign->id]);

        $this->actingAs($me)->get("/crm/quotes/{$theirs->id}")->assertForbidden();
        $this->actingAs($me)->get("/crm/quotes/{$theirs->id}/pdf")->assertForbidden();
        $this->actingAs($me)->post('/crm/quotes', $this->payload($foreign))->assertForbidden();

        $otherOpp = Opportunity::factory()->create(['client_id' => $foreign->id]);
        $this->actingAs($me)->post('/crm/quotes', $this->payload($mine, ['opportunity_id' => $otherOpp->id]))->assertSessionHasErrors('opportunity_id');

        $this->actingAs($me)->post('/crm/quotes', $this->payload($mine))->assertRedirect();
        $this->actingAs($me)->get('/crm/quotes')->assertInertia(fn (Assert $p) => $p->has('quotes.data', 1)->where('quotes.data.0.client.id', $mine->id));

        $nobody = $this->userWith();
        $this->actingAs($nobody)->get('/crm/quotes')->assertForbidden();
    }

    public function test_pdf_is_generated_with_company_identity(): void
    {
        $u = $this->sales();
        $client = Client::factory()->create(['name' => 'منبر منظمات المجتمع المدني']);
        $this->actingAs($u)->post('/crm/quotes', $this->payload($client));
        $q = Quote::first();

        $res = $this->actingAs($u)->get("/crm/quotes/{$q->id}/pdf");
        $res->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertStringContainsString('inline; filename="draft-'.$q->id.'.pdf"', $res->headers->get('Content-Disposition'));

        $this->actingAs($u)->post("/crm/quotes/{$q->id}/issue");
        $q->refresh();
        $res = $this->actingAs($u)->get("/crm/quotes/{$q->id}/pdf?download=1");
        $res->assertOk();
        $this->assertStringContainsString('attachment; filename="'.$q->number.'.pdf"', $res->headers->get('Content-Disposition'));

        $data = QuotePdf::data($q);
        $this->assertFileExists($data['logo']);
        $this->assertSame('Insight Media', $data['company']['name']);
        $this->assertSame(1230.0, $data['totals']['total']);
    }

    public function test_quote_defaults_settings(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put('/settings/quote-defaults', ['validity_days' => 14, 'default_terms' => 'دفعة مقدمة 50%'])->assertRedirect();
        $client = Client::factory()->create();
        $this->actingAs($admin)->get("/crm/quotes/create?client_id={$client->id}")
            ->assertInertia(fn (Assert $p) => $p->where('form.terms', 'دفعة مقدمة 50%')->where('form.valid_until', today()->addDays(14)->toDateString()));
    }
}

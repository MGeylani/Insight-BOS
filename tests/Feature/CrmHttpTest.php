<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contact;
use App\Models\CrmActivity;
use App\Models\Lead;
use App\Models\Opportunity;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CrmHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CurrencySeeder::class);
    }

    private function salesUser(string $scope = 'all'): \App\Models\User
    {
        return $this->userWith([
            'crm.view' => $scope, 'crm.create' => $scope, 'crm.edit' => $scope,
            'crm.delete' => $scope, 'crm.convert' => $scope,
        ]);
    }

    public function test_crm_pages_need_crm_view(): void
    {
        $nobody = $this->userWith();
        foreach (['/crm/leads', '/crm/clients', '/crm/opportunities', '/crm/activities'] as $url) {
            $this->actingAs($nobody)->get($url)->assertForbidden();
        }

        $viewer = $this->userWith(['crm.view' => 'all']);
        $this->actingAs($viewer)->get('/crm/leads')->assertOk()->assertInertia(fn (Assert $p) => $p->component('crm/leads/Index'));
        $this->actingAs($viewer)->get('/crm/clients')->assertOk()->assertInertia(fn (Assert $p) => $p->component('crm/clients/Index'));
        $this->actingAs($viewer)->get('/crm/opportunities')->assertOk()->assertInertia(fn (Assert $p) => $p->component('crm/opportunities/Index'));
        $this->actingAs($viewer)->get('/crm/activities')->assertOk()->assertInertia(fn (Assert $p) => $p->component('crm/Activities'));
        $this->actingAs($viewer)->post('/crm/leads', ['name' => 'x'])->assertForbidden();
    }

    public function test_create_lead_and_board_shows_only_own_for_own_scope(): void
    {
        $me = $this->salesUser('own');
        $this->actingAs($me)->post('/crm/leads', ['name' => 'سارة', 'company_name' => 'Nova', 'source' => 'website', 'rating' => 'hot', 'expected_value' => 1200])
            ->assertRedirect();
        $lead = Lead::firstWhere('name', 'سارة');
        $this->assertSame($me->id, $lead->owner_id);
        $this->assertSame('new', $lead->stage);

        $other = Lead::factory()->create();
        $this->actingAs($me)->get('/crm/leads')->assertInertia(fn (Assert $p) => $p->has('leads', 1)->where('leads.0.id', $lead->id));
        $this->actingAs($me)->get("/crm/leads/{$other->id}")->assertForbidden();
        $this->actingAs($me)->put("/crm/leads/{$other->id}", ['name' => 'x'])->assertForbidden();
        $this->actingAs($me)->get("/crm/leads/{$lead->id}")->assertOk();
    }

    public function test_kanban_move_updates_stage_and_order(): void
    {
        $u = $this->salesUser();
        $a = Lead::factory()->create(['stage' => 'new']);
        $b = Lead::factory()->create(['stage' => 'contacted', 'position' => 0]);

        $this->actingAs($u)->post("/crm/leads/{$a->id}/move", ['stage' => 'contacted', 'order' => [$a->id, $b->id]])->assertRedirect();

        $this->assertSame('contacted', $a->fresh()->stage);
        $this->assertSame(0, $a->fresh()->position);
        $this->assertSame(1, $b->fresh()->position);
        $this->actingAs($u)->post("/crm/leads/{$a->id}/move", ['stage' => 'converted'])->assertSessionHasErrors('stage');
    }

    public function test_duplicate_endpoint_and_merge(): void
    {
        $u = $this->salesUser();
        $keep = Lead::factory()->create(['email' => 'a@x.com']);
        $dupe = Lead::factory()->create(['email' => 'A@x.com']);

        $this->actingAs($u)->getJson('/crm/leads/duplicates?email=a@x.com&ignore='.$keep->id)
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $dupe->id);

        $this->actingAs($u)->get("/crm/leads/{$keep->id}")
            ->assertInertia(fn (Assert $p) => $p->component('crm/leads/Show')->has('duplicates', 1));

        $this->actingAs($u)->post("/crm/leads/{$keep->id}/merge", ['duplicate_id' => $dupe->id])->assertRedirect();
        $this->assertSoftDeleted($dupe);
        $this->actingAs($u)->get("/crm/leads/{$dupe->id}")->assertNotFound();
    }

    public function test_convert_wizard_needs_convert_permission(): void
    {
        $lead = Lead::factory()->create(['company_name' => 'ULFED']);
        $noConvert = $this->userWith(['crm.view' => 'all', 'crm.edit' => 'all']);
        $this->actingAs($noConvert)->post("/crm/leads/{$lead->id}/convert", [])->assertForbidden();

        $u = $this->salesUser();
        $this->actingAs($u)->post("/crm/leads/{$lead->id}/convert", [
            'contact' => ['job_title' => 'مدير'],
            'create_opportunity' => true,
            'opportunity' => ['title' => 'مواد معرض STK', 'amount' => 5000, 'currency' => 'USD', 'stage' => 'proposal'],
        ])->assertRedirect('/crm/opportunities/'.Opportunity::first()->id);

        $client = Client::firstWhere('name', 'ULFED');
        $this->assertNotNull($client);
        $this->assertSame(1, $client->contacts()->count());
        $this->assertSame('converted', $lead->fresh()->stage);

        $this->actingAs($u)->post("/crm/leads/{$lead->id}/convert", ['create_opportunity' => false])->assertSessionHasErrors('lead');
    }

    public function test_client_360_and_contacts(): void
    {
        $u = $this->salesUser();
        $this->actingAs($u)->post('/crm/clients', ['name' => 'Nova', 'type' => 'company', 'currency' => 'USD'])->assertRedirect();
        $client = Client::firstWhere('name', 'Nova');

        $this->actingAs($u)->post("/crm/clients/{$client->id}/contacts", ['name' => 'أحمد'])->assertRedirect();
        $this->actingAs($u)->post("/crm/clients/{$client->id}/contacts", ['name' => 'ليلى', 'is_primary' => true])->assertRedirect();
        $this->assertSame('ليلى', $client->primaryContact()->value('name'));
        $this->assertSame(1, Contact::where('client_id', $client->id)->where('is_primary', true)->count());

        Opportunity::factory()->for($client)->create(['stage' => 'won', 'probability' => 100, 'amount' => 800]);
        Opportunity::factory()->for($client)->create(['amount' => 300]);

        $this->actingAs($u)->get("/crm/clients/{$client->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('crm/clients/Show')
            ->has('contacts', 2)
            ->has('opportunities', 2)
            ->where('stats.won_total', 800)
            ->where('stats.open_count', 1));

        $foreign = Contact::factory()->create();
        $this->actingAs($u)->put("/crm/clients/{$client->id}/contacts/{$foreign->id}", ['name' => 'x'])->assertNotFound();
    }

    public function test_opportunity_stage_route_enforces_lost_reason(): void
    {
        $u = $this->salesUser();
        $client = Client::factory()->create();
        $this->actingAs($u)->post('/crm/opportunities', [
            'client_id' => $client->id, 'title' => 'موقع', 'amount' => 1000, 'currency' => 'USD', 'stage' => 'discovery',
        ])->assertRedirect();
        $opp = Opportunity::firstWhere('title', 'موقع');
        $this->assertSame(1, $opp->stageChanges()->count());

        $this->actingAs($u)->post("/crm/opportunities/{$opp->id}/stage", ['stage' => 'lost'])->assertSessionHasErrors('lost_reason');
        $this->actingAs($u)->post("/crm/opportunities/{$opp->id}/stage", ['stage' => 'lost', 'lost_reason' => 'competitor'])->assertSessionHasNoErrors();
        $this->assertSame('lost', $opp->fresh()->stage);

        $this->actingAs($u)->get('/crm/opportunities?closed=1')->assertInertia(fn (Assert $p) => $p->has('opportunities', 1)->where('forecast.count', 0));
        $this->actingAs($u)->get("/crm/opportunities/{$opp->id}")->assertInertia(fn (Assert $p) => $p->has('history', 2));
    }

    public function test_forecast_is_weighted(): void
    {
        $u = $this->salesUser();
        Opportunity::factory()->create(['amount' => 1000, 'probability' => 50, 'expected_close_date' => '2027-01-15']);
        Opportunity::factory()->create(['amount' => 2000, 'probability' => 25, 'expected_close_date' => '2027-01-20']);

        $this->actingAs($u)->get('/crm/opportunities')->assertInertia(fn (Assert $p) => $p
            ->where('forecast.total', 3000)
            ->where('forecast.weighted', 1000)
            ->where('forecast.months.0.month', '2027-01'));
    }

    public function test_activities_log_complete_and_reschedule_reminder(): void
    {
        $u = $this->salesUser();
        $lead = Lead::factory()->create();

        $this->actingAs($u)->post('/crm/activities', [
            'subject_type' => 'lead', 'subject_id' => $lead->id, 'type' => 'call', 'title' => 'اتصال أول',
            'due_at' => now()->subDay()->toDateTimeString(), 'remind_at' => now()->subHour()->toDateTimeString(),
        ])->assertRedirect();
        $a = CrmActivity::first();
        $this->assertSame($u->id, $a->user_id);

        $this->actingAs($u)->get('/crm/activities')->assertInertia(fn (Assert $p) => $p->has('overdue', 1)->where('overdue.0.subject.url', "/crm/leads/{$lead->id}"));

        $a->forceFill(['reminded_at' => now()])->save();
        $this->actingAs($u)->put("/crm/activities/{$a->id}", ['type' => 'call', 'title' => 'اتصال أول', 'remind_at' => now()->addDay()->toDateTimeString()]);
        $this->assertNull($a->fresh()->reminded_at);

        $this->actingAs($u)->post("/crm/activities/{$a->id}/complete")->assertRedirect();
        $this->assertNotNull($a->fresh()->completed_at);

        $outsider = $this->userWith(['crm.view' => 'own']);
        $this->actingAs($outsider)->post('/crm/activities', ['subject_type' => 'lead', 'subject_id' => $lead->id, 'type' => 'note', 'title' => 'x'])->assertForbidden();
    }

    public function test_notifications_endpoints(): void
    {
        $u = $this->salesUser();
        CrmActivity::factory()->create(['user_id' => $u->id, 'remind_at' => now()->subMinute()]);
        $this->artisan('crm:reminders');

        $this->actingAs($u)->getJson('/notifications')->assertOk()->assertJsonCount(1)->assertJsonPath('0.read', false);
        $this->actingAs($u)->post('/notifications/read')->assertRedirect();
        $this->assertSame(0, $u->unreadNotifications()->count());
    }
}

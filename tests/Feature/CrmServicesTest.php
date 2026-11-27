<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contact;
use App\Models\CrmActivity;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Notifications\ActivityReminder;
use App\Services\Crm\DuplicateFinder;
use App\Services\Crm\LeadConverter;
use App\Services\Crm\LeadMerger;
use App\Services\Crm\OpportunityStages;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CrmServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_is_normalized_for_matching(): void
    {
        $lead = Lead::factory()->create(['phone' => '+90 (532) 111 22 33']);
        $this->assertSame('532112233', $lead->phone_normalized);
        $this->assertSame('532112233', Lead::normalizePhone('0532 111 22 33'));
        $this->assertNull(Lead::normalizePhone('12'));
    }

    public function test_duplicates_found_by_email_phone_and_company(): void
    {
        $lead = Lead::factory()->create(['email' => 'Sara@Example.com', 'phone' => '+905321112233', 'company_name' => 'Nova']);
        $client = Client::factory()->create(['name' => 'ULFED']);
        $contact = Contact::factory()->for($client)->create(['phone' => '00963 944 555 666']);

        $byEmail = DuplicateFinder::find(['email' => 'sara@example.com']);
        $this->assertSame(['lead'], array_column($byEmail, 'type'));
        $this->assertSame(['email'], $byEmail[0]['matched_on']);

        $byPhone = DuplicateFinder::find(['phone' => '0532 111 22 33']);
        $this->assertSame($lead->id, $byPhone[0]['id']);

        $byCompany = DuplicateFinder::find(['company_name' => ' ulfed ']);
        $this->assertSame([['client', $client->id]], array_map(fn ($r) => [$r['type'], $r['id']], $byCompany));

        $contactMatch = DuplicateFinder::find(['phone' => '0944555666']);
        $this->assertSame('contact', $contactMatch[0]['type']);

        $this->assertSame([], DuplicateFinder::find(['email' => 'sara@example.com'], $lead->id));
        $this->assertSame([], DuplicateFinder::find([]));
    }

    public function test_merge_fills_gaps_moves_activities_and_archives_duplicate(): void
    {
        $this->actingAs($this->admin());
        $keep = Lead::factory()->create(['phone' => null, 'notes' => 'أ']);
        $dupe = Lead::factory()->create(['phone' => '+90 555 444 3322', 'notes' => 'ب']);
        CrmActivity::factory()->create(['subject_type' => 'lead', 'subject_id' => $dupe->id]);

        $merged = LeadMerger::merge($keep, $dupe);

        $this->assertSame('+90 555 444 3322', $merged->phone);
        $this->assertStringContainsString('ب', $merged->notes);
        $this->assertSame(1, $merged->activities()->count());
        $this->assertSoftDeleted($dupe);
        $this->assertSame($keep->id, Lead::withTrashed()->find($dupe->id)->merged_into_id);
        $this->assertDatabaseHas('activity_log', ['action' => 'crm.lead_merged', 'subject_id' => $keep->id]);
    }

    public function test_conversion_creates_client_contact_and_opportunity(): void
    {
        $this->actingAs($user = $this->admin());
        $lead = Lead::factory()->create(['company_name' => 'Nova', 'name' => 'Sara', 'expected_value' => 3000, 'interest' => 'هوية بصرية']);
        CrmActivity::factory()->create(['subject_type' => 'lead', 'subject_id' => $lead->id]);

        $r = LeadConverter::convert($lead, ['contact' => ['job_title' => 'CEO'], 'opportunity' => ['stage' => 'proposal']]);

        $this->assertSame('Nova', $r['client']->name);
        $this->assertSame('company', $r['client']->type);
        $this->assertSame('CEO', $r['contact']->job_title);
        $this->assertTrue($r['contact']->is_primary);
        $this->assertSame('هوية بصرية', $r['opportunity']->title);
        $this->assertSame('3000.00', $r['opportunity']->amount);
        $this->assertSame(30, $r['opportunity']->probability);
        $this->assertSame(1, $r['opportunity']->stageChanges()->count());
        $this->assertSame(1, $r['client']->activities()->count());

        $lead->refresh();
        $this->assertTrue($lead->isConverted());
        $this->assertSame($r['client']->id, $lead->client_id);

        $this->expectException(ValidationException::class);
        LeadConverter::convert($lead, ['contact' => []]);
    }

    public function test_conversion_can_attach_to_existing_client_without_opportunity(): void
    {
        $this->actingAs($this->admin());
        $client = Client::factory()->create();
        Contact::factory()->for($client)->create(['is_primary' => true]);
        $lead = Lead::factory()->create();

        $r = LeadConverter::convert($lead, ['client_id' => $client->id, 'contact' => []]);

        $this->assertTrue($r['client']->is($client));
        $this->assertFalse($r['contact']->is_primary);
        $this->assertNull($r['opportunity']);
        $this->assertSame(1, Client::count());
    }

    public function test_lost_requires_reason_and_stage_history_is_kept(): void
    {
        $this->actingAs($this->admin());
        $opp = Opportunity::factory()->create();

        try {
            OpportunityStages::move($opp, 'lost');
            $this->fail('Lost without a reason must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lost_reason', $e->errors());
        }

        try {
            OpportunityStages::move($opp->fresh(), 'lost', null, 'other', '  ');
            $this->fail('"other" without a note must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lost_note', $e->errors());
        }

        OpportunityStages::move($opp, 'negotiation', 60);
        $this->assertSame(60, $opp->probability);
        OpportunityStages::move($opp, 'lost', null, 'price');

        $opp->refresh();
        $this->assertSame('lost', $opp->stage);
        $this->assertSame(0, $opp->probability);
        $this->assertSame('price', $opp->lost_reason);
        $this->assertNotNull($opp->closed_at);
        $this->assertSame(['negotiation', 'lost'], $opp->stageChanges()->reorder('id')->pluck('to_stage')->all());

        OpportunityStages::move($opp, 'proposal');
        $opp->refresh();
        $this->assertNull($opp->lost_reason);
        $this->assertNull($opp->closed_at);
    }

    public function test_weighted_value_in_base_currency(): void
    {
        $this->seed(CurrencySeeder::class);
        $this->actingAs($this->admin());
        $this->post('/settings/currencies/rates', ['date' => now()->toDateString(), 'rates' => ['TRY' => 40]]);

        $opp = Opportunity::factory()->create(['amount' => 4000, 'currency' => 'TRY', 'probability' => 50]);
        $this->assertEquals(100.0, $opp->amountInBase());
        $this->assertEquals(50.0, $opp->weightedInBase());
    }

    public function test_visibility_scope_limits_own_grants(): void
    {
        $mine = $this->userWith(['crm.view' => 'own']);
        $all = $this->userWith(['crm.view' => 'all']);
        $none = $this->userWith();

        Lead::factory()->create(['owner_id' => $mine->id]);
        Lead::factory()->create(['created_by' => $mine->id]);
        Lead::factory()->count(3)->create();

        $this->assertSame(2, Lead::visibleTo($mine)->count());
        $this->assertSame(5, Lead::visibleTo($all)->count());
        $this->assertSame(0, Lead::visibleTo($none)->count());
    }

    public function test_reminder_command_notifies_once(): void
    {
        Notification::fake();
        $user = $this->userWith(['crm.view' => 'own']);
        $due = CrmActivity::factory()->create(['user_id' => $user->id, 'remind_at' => now()->subMinute()]);
        CrmActivity::factory()->create(['user_id' => $user->id, 'remind_at' => now()->addHour()]);
        $done = CrmActivity::factory()->create(['user_id' => $user->id, 'remind_at' => now()->subMinute()]);
        $done->forceFill(['completed_at' => now()])->save();

        $this->artisan('crm:reminders')->assertSuccessful();
        $this->artisan('crm:reminders')->assertSuccessful();

        Notification::assertSentToTimes($user, ActivityReminder::class, 1);
        $this->assertNotNull($due->fresh()->reminded_at);
    }
}

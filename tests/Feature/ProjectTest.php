<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ChangeRequest;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Project;
use App\Models\Quote;
use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\NumberSequenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, NumberSequenceSeeder::class]);
    }

    private function pm(string $scope = 'all', array $extra = []): User
    {
        return $this->userWith($extra + [
            'crm.view' => 'all',
            'projects.view' => $scope, 'projects.create' => $scope, 'projects.edit' => $scope, 'projects.delete' => $scope,
            'projects.manage_budget' => $scope, 'projects.approve_changes' => $scope,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Client $client, array $extra = []): array
    {
        return $extra + [
            'name' => 'هوية بصرية — منبر',
            'type' => 'identity',
            'template' => 'identity',
            'client_id' => $client->id,
            'status' => 'planning',
            'currency' => 'USD',
            'budget' => 3000,
            'start_date' => '2026-12-01',
            'due_date' => '2027-01-15',
            'description' => 'شعار ودليل هوية',
            'members' => [],
        ];
    }

    public function test_create_from_template_adds_milestones_risks_and_number(): void
    {
        $u = $this->pm();
        $designer = User::factory()->create();
        $client = Client::factory()->create();

        $this->actingAs($u)->post('/projects', $this->payload($client, [
            'manager_id' => $u->id,
            'members' => [['user_id' => $designer->id, 'role' => 'مصمم'], ['user_id' => $u->id, 'role' => 'x']],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $p = Project::first();
        $this->assertSame('PRJ-'.now()->year.'-0001', $p->number);
        $this->assertSame('identity', $p->template);
        $this->assertSame('3000.00', $p->budget);
        $tpl = config('projects.templates.identity');
        $this->assertCount(count($tpl['milestones']), $p->milestones);
        $this->assertSame('2026-12-04', $p->milestones->first()->due_date->toDateString()); // start + 3 days
        $this->assertCount(count($tpl['risks']), $p->risks);
        $this->assertSame($u->id, $p->risks->first()->owner_id);
        // The manager is never listed as a member.
        $this->assertSame([$designer->id], $p->members->pluck('id')->all());
        $this->assertSame('مصمم', $p->members->first()->pivot->role);
        $this->assertSame(0, $p->progress());
        $this->assertTrue(ActivityLog::where('action', 'projects.created')->where('subject_id', $p->id)->exists());

        $this->actingAs($u)->get("/projects/{$p->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('projects/Show')
                ->where('project.number', $p->number)->where('project.budget', 3000)
                ->has('milestones', count($tpl['milestones']))->has('project.members', 1)
                ->where('can.edit', true)->where('can.approve', true));
        $this->actingAs($u)->get('/projects')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('projects/Index')->has('projects.data', 1)->where('summary.planning', 1));
    }

    public function test_create_page_prefills_and_without_template(): void
    {
        $u = $this->pm();
        $client = Client::factory()->create(['currency' => 'TRY']);
        $this->actingAs($u)->get("/projects/create?client_id={$client->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('projects/Form')
                ->where('form.client_id', $client->id)->where('form.currency', 'TRY')->where('form.template', 'identity'));

        $this->actingAs($u)->post('/projects', $this->payload($client, ['template' => null, 'type' => 'other']))->assertSessionHasNoErrors();
        $p = Project::first();
        $this->assertNull($p->template);
        $this->assertCount(0, $p->milestones);
        $this->assertNull($p->progress());
    }

    public function test_project_from_accepted_quote_links_back_once(): void
    {
        $u = $this->pm();
        $client = Client::factory()->create();
        $quote = Quote::factory()->create(['client_id' => $client->id, 'status' => 'accepted', 'number' => 'QT-2026-0009']);
        $quote->items()->create(['name' => 'تصميم شعار', 'quantity' => 2, 'unit_price' => 450]);

        $this->actingAs($u)->get("/projects/create?quote_id={$quote->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('form.quote_id', $quote->id)->where('form.budget', 900)
                ->where('form.client_id', $client->id)->where('form.name', $quote->title));

        $this->actingAs($u)->post('/projects', $this->payload($client, ['quote_id' => $quote->id]))->assertSessionHasNoErrors();
        $p = Project::first();
        $this->assertSame($p->id, $quote->fresh()->project_id);

        // A quote turns into one project only.
        $this->actingAs($u)->post('/projects', $this->payload($client, ['quote_id' => $quote->id]))->assertSessionHasErrors('quote_id');

        // The quote page links to the project.
        $this->actingAs($u)->get("/crm/quotes/{$quote->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('project.id', $p->id)->where('project.can_open', true));
    }

    public function test_quote_must_be_accepted_and_of_the_same_client(): void
    {
        $u = $this->pm();
        $client = Client::factory()->create();
        $sent = Quote::factory()->create(['client_id' => $client->id, 'status' => 'sent']);
        $other = Quote::factory()->create(['status' => 'accepted']);

        $this->actingAs($u)->post('/projects', $this->payload($client, ['quote_id' => $sent->id]))->assertSessionHasErrors('quote_id');
        $this->actingAs($u)->post('/projects', $this->payload($client, ['quote_id' => $other->id]))->assertSessionHasErrors('quote_id');
        $this->assertSame(0, Project::count());
    }

    public function test_project_from_contract_and_budget_without_permission_comes_from_source(): void
    {
        $u = $this->userWith(['crm.view' => 'all', 'projects.view' => 'all', 'projects.create' => 'all', 'projects.edit' => 'all']);
        $client = Client::factory()->create();
        $contract = Contract::factory()->create(['client_id' => $client->id, 'status' => 'active', 'value' => 2500, 'number' => 'CTR-2026-0003']);

        $this->actingAs($u)->get("/projects/create?contract_id={$contract->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('form.contract_id', $contract->id)->where('canBudget', false));

        // The posted budget is ignored: it is taken from the contract.
        $this->actingAs($u)->post('/projects', $this->payload($client, ['contract_id' => $contract->id, 'budget' => 99999]))->assertSessionHasNoErrors();
        $p = Project::first();
        $this->assertSame('2500.00', $p->budget);
        $this->assertSame($p->id, $contract->fresh()->project_id);

        // Editing without budget permission keeps the budget.
        $this->actingAs($u)->put("/projects/{$p->id}", $this->payload($client, ['contract_id' => $contract->id, 'budget' => 1, 'name' => 'اسم جديد']))->assertSessionHasNoErrors();
        $p->refresh();
        $this->assertSame('اسم جديد', $p->name);
        $this->assertSame('2500.00', $p->budget);

        // A draft contract cannot be linked.
        $draft = Contract::factory()->create(['client_id' => $client->id]);
        $this->actingAs($u)->post('/projects', $this->payload($client, ['contract_id' => $draft->id]))->assertSessionHasErrors('contract_id');
    }

    public function test_assigned_scope_sees_only_own_and_member_projects(): void
    {
        $member = $this->userWith(['projects.view' => 'assigned', 'projects.edit' => 'assigned']);
        $mine = Project::factory()->create();
        $mine->members()->attach($member->id, ['role' => 'مصمم']);
        $managed = Project::factory()->create(['manager_id' => $member->id]);
        $other = Project::factory()->create();

        $this->actingAs($member)->get('/projects')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('projects.data', 2)->where('seeBudget', false)->where('projects.data.0.budget', null));
        $this->actingAs($member)->get("/projects/{$mine->id}")->assertOk();
        $this->actingAs($member)->get("/projects/{$managed->id}")->assertOk();
        $this->actingAs($member)->get("/projects/{$other->id}")->assertForbidden();
        $this->actingAs($member)->post("/projects/{$other->id}/milestones", ['title' => 'x'])->assertForbidden();

        // "own" does not include membership.
        $own = $this->userWith(['projects.view' => 'own']);
        $mine->members()->attach($own->id);
        $this->actingAs($own)->get("/projects/{$mine->id}")->assertForbidden();

        $this->actingAs($this->userWith())->get('/projects')->assertForbidden();
    }

    public function test_change_request_approval_updates_budget_and_due_date(): void
    {
        $u = $this->pm();
        $p = Project::factory()->create(['budget' => 1000, 'due_date' => '2027-01-10']);

        $this->actingAs($u)->post("/projects/{$p->id}/changes", [
            'title' => '4 منشورات إضافية', 'source' => 'client', 'cost_impact' => 250.5, 'days_impact' => 5,
        ])->assertSessionHasNoErrors();
        $this->actingAs($u)->post("/projects/{$p->id}/changes", ['title' => 'حذف فيديو', 'source' => 'internal', 'cost_impact' => -100])->assertSessionHasNoErrors();
        [$cr1, $cr2] = ChangeRequest::orderBy('seq')->get()->all();
        $this->assertSame(1, $cr1->seq);
        $this->assertSame(2, $cr2->seq);
        $this->assertSame('CR-2', $cr2->code());

        // Someone who can edit but not approve.
        $editor = $this->userWith(['projects.view' => 'all', 'projects.edit' => 'all']);
        $this->actingAs($editor)->post("/projects/{$p->id}/changes/{$cr1->id}/approve")->assertForbidden();

        $this->actingAs($u)->post("/projects/{$p->id}/changes/{$cr1->id}/approve", ['decision_note' => 'موافق'])->assertSessionHasNoErrors();
        $p->refresh();
        $this->assertSame('1250.50', $p->budget);
        $this->assertSame('2027-01-15', $p->due_date->toDateString());
        $cr1->refresh();
        $this->assertSame('approved', $cr1->status);
        $this->assertSame('1000.00', $cr1->budget_before);
        $this->assertSame($u->id, $cr1->decided_by);

        // Decided once only.
        $this->actingAs($u)->post("/projects/{$p->id}/changes/{$cr1->id}/approve")->assertSessionHasErrors('status');
        $this->assertSame('1250.50', $p->fresh()->budget);

        // Rejection needs a reason and changes nothing.
        $this->actingAs($u)->post("/projects/{$p->id}/changes/{$cr2->id}/reject", [])->assertSessionHasErrors('decision_note');
        $this->actingAs($u)->post("/projects/{$p->id}/changes/{$cr2->id}/reject", ['decision_note' => 'لا'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $cr2->fresh()->status);
        $this->assertSame('1250.50', $p->fresh()->budget);

        // A change request of another project is not reachable through this one.
        $other = Project::factory()->create();
        $this->actingAs($u)->post("/projects/{$other->id}/changes/{$cr2->id}/approve")->assertNotFound();

        $this->actingAs($u)->get("/projects/{$p->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('changes', 2)->where('project.approved_cost', 250.5)->where('project.approved_days', 5));
    }

    public function test_milestones_risks_and_issues(): void
    {
        $u = $this->pm();
        $p = Project::factory()->create();

        $this->actingAs($u)->post("/projects/{$p->id}/milestones", ['title' => 'أ', 'due_date' => '2026-12-10'])->assertSessionHasNoErrors();
        $this->actingAs($u)->post("/projects/{$p->id}/milestones", ['title' => 'ب'])->assertSessionHasNoErrors();
        [$a, $b] = $p->milestones()->get()->all();
        $this->actingAs($u)->post("/projects/{$p->id}/milestones/{$b->id}/move", ['dir' => 'up'])->assertRedirect();
        $this->assertSame(['ب', 'أ'], $p->milestones()->pluck('title')->all());

        $this->actingAs($u)->post("/projects/{$p->id}/milestones/{$a->id}/toggle", ['done' => true])->assertSessionHasNoErrors();
        $a->refresh();
        $this->assertSame('done', $a->status);
        $this->assertSame($u->id, $a->done_by);
        $this->assertSame(50, $p->fresh()->progress());
        $this->actingAs($u)->delete("/projects/{$p->id}/milestones/{$b->id}")->assertRedirect();
        $this->assertSame(100, $p->fresh()->progress());

        $this->actingAs($u)->post("/projects/{$p->id}/risks", [
            'title' => 'تأخر الموافقات', 'category' => 'client', 'probability' => 3, 'impact' => 2, 'status' => 'open',
        ])->assertSessionHasNoErrors();
        $risk = $p->risks()->first();
        $this->assertSame(6, $risk->score());
        $this->assertSame('high', $risk->level());
        $this->actingAs($u)->put("/projects/{$p->id}/risks/{$risk->id}", [
            'title' => 'تأخر الموافقات', 'category' => 'client', 'probability' => 1, 'impact' => 2, 'status' => 'mitigated',
        ])->assertSessionHasNoErrors();
        $this->assertSame('low', $risk->fresh()->level());

        $this->actingAs($u)->post("/projects/{$p->id}/issues", ['title' => 'الطابعة معطلة', 'severity' => 'high', 'status' => 'open'])->assertSessionHasNoErrors();
        $issue = $p->issues()->first();
        $this->actingAs($u)->put("/projects/{$p->id}/issues/{$issue->id}", ['title' => 'الطابعة معطلة', 'severity' => 'high', 'status' => 'resolved'])
            ->assertSessionHasErrors('resolution');
        $this->actingAs($u)->put("/projects/{$p->id}/issues/{$issue->id}", ['title' => 'الطابعة معطلة', 'severity' => 'high', 'status' => 'resolved', 'resolution' => 'مطبعة بديلة'])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($issue->fresh()->resolved_at);

        $this->actingAs($u)->get('/projects')->assertInertia(fn (Assert $page) => $page->where('summary.open_issues', 0));
    }

    public function test_status_change_sets_completed_at_and_overdue_is_derived(): void
    {
        $u = $this->pm();
        $p = Project::factory()->create(['due_date' => today()->subDay()->toDateString()]);
        $this->assertTrue($p->isOverdue());
        $this->actingAs($u)->get('/projects?status=overdue')->assertInertia(fn (Assert $page) => $page->has('projects.data', 1)->where('summary.overdue', 1));

        $this->actingAs($u)->post("/projects/{$p->id}/status", ['status' => 'completed'])->assertSessionHasNoErrors();
        $p->refresh();
        $this->assertNotNull($p->completed_at);
        $this->assertFalse($p->isOverdue());

        $this->actingAs($u)->post("/projects/{$p->id}/status", ['status' => 'active'])->assertSessionHasNoErrors();
        $this->assertNull($p->fresh()->completed_at);
    }

    public function test_archive_and_client_page_lists_projects(): void
    {
        $u = $this->pm();
        $client = Client::factory()->create();
        $p = Project::factory()->create(['client_id' => $client->id]);

        $this->actingAs($u)->get("/crm/clients/{$client->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('projects', 1)->where('projects.0.id', $p->id));
        // Without projects.view the client page shows no projects.
        $sales = $this->userWith(['crm.view' => 'all']);
        $this->actingAs($sales)->get("/crm/clients/{$client->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->has('projects', 0));

        $this->actingAs($u)->delete("/projects/{$p->id}")->assertRedirect('/projects');
        $this->assertSoftDeleted($p);
    }
}

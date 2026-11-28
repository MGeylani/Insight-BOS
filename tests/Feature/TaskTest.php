<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Project;
use App\Models\StatusScheme;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Services\Tasks\TaskLists;
use App\Services\Tasks\Tasks;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\NumberSequenceSeeder;
use Database\Seeders\TaskSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, NumberSequenceSeeder::class, TaskSeeder::class]);
    }

    private function full(array $extra = []): User
    {
        return $this->userWith($extra + [
            'crm.view' => 'all', 'projects.view' => 'all', 'projects.create' => 'all', 'projects.edit' => 'all',
            'tasks.view' => 'all', 'tasks.create' => 'all', 'tasks.edit' => 'all', 'tasks.delete' => 'all', 'tasks.assign' => 'all',
        ]);
    }

    private function list(?Client $client = null, ?Project $project = null, string $name = 'المطبوعات'): TaskList
    {
        $client ??= $project?->client ?? Client::factory()->create(['name' => 'منبر ULFED']);

        return TaskLists::create($client, $project, ['name' => $name], null, false);
    }

    private function task(TaskList $list, User $u, array $data = []): Task
    {
        return Tasks::create($list, $data + ['title' => 'مهمة '.uniqid()], $u);
    }

    public function test_seeder_creates_templates_and_internal_client_idempotently(): void
    {
        $this->seed(TaskSeeder::class);
        $this->assertSame(2, StatusScheme::where('is_template', true)->count());
        $agency = StatusScheme::defaultTemplate();
        $this->assertSame('قالب الوكالة', $agency->name);
        $this->assertSame(['موجز', 'قيد التنفيذ', 'مراجعة داخلية', 'بانتظار العميل', 'تعديلات', 'معتمد', 'منشور'], $agency->statuses->pluck('name')->all());
        $internal = Client::where('is_internal', true)->get();
        $this->assertCount(1, $internal);
        $this->assertSame('Insight Media', $internal[0]->name);
        $this->assertSame('INS', $internal[0]->task_prefix);
    }

    public function test_project_from_template_creates_one_list_per_milestone(): void
    {
        $u = $this->full();
        $client = Client::factory()->create(['name' => 'منبر ULFED']);
        $this->actingAs($u)->post('/projects', [
            'name' => 'معرض STK', 'type' => 'exhibition', 'template' => 'exhibition', 'client_id' => $client->id, 'status' => 'active',
            'currency' => 'USD', 'budget' => 1000, 'start_date' => '2026-11-01', 'due_date' => '2026-12-01', 'members' => [],
        ])->assertSessionHasNoErrors();
        $p = Project::first();
        $milestones = $p->milestones;
        $lists = $p->taskLists;
        $this->assertCount($milestones->count(), $lists);
        $this->assertSame($milestones->pluck('id')->all(), $lists->pluck('project_milestone_id')->all());
        $this->assertSame($milestones->first()->title, $lists->first()->name);
        $this->assertSame('ULF', $client->fresh()->task_prefix);
        // Each list owns a copy of the default template.
        $this->assertCount($lists->count(), $lists->pluck('status_scheme_id')->unique());
        $this->assertSame(7, $lists->first()->statuses()->count());

        // A project without a template gets the default list.
        $this->actingAs($u)->post('/projects', [
            'name' => 'أخرى', 'type' => 'other', 'template' => null, 'client_id' => $client->id, 'status' => 'active', 'currency' => 'USD', 'members' => [],
        ])->assertSessionHasNoErrors();
        $this->assertSame(['المهام'], Project::latest('id')->first()->taskLists->pluck('name')->all());
    }

    public function test_task_ids_are_per_client_and_internal_tasks_need_no_list(): void
    {
        $u = $this->full();
        $list = $this->list();
        $a = $this->task($list, $u);
        $b = $this->task($list, $u);
        $this->assertSame('ULF-1', $a->code());
        $this->assertSame('ULF-2', $b->code());

        $this->actingAs($u)->post('/tasks', ['title' => 'تجهيز عرض الشركة'])->assertSessionHasNoErrors();
        $internal = Task::latest('id')->first();
        $this->assertSame('INS-1', $internal->code());
        $this->assertSame('مهام عامة', $internal->list->name);
        $this->assertTrue($internal->client->is_internal);
        // Creator is the default assignee and a watcher.
        $this->assertSame([$u->id], $internal->assignees->pluck('id')->all());
        $this->assertSame([$u->id], $internal->watchers->pluck('id')->all());

        // Searching by id finds the task.
        $this->actingAs($u)->get('/tasks?search=ULF-2')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('tasks/Index')->has('tasks', 1)->where('tasks.0.code', 'ULF-2'));
    }

    public function test_prefix_suggestions_are_unique(): void
    {
        $this->assertSame('ULF', TaskLists::suggestPrefix('منبر منظمات المجتمع المدني ULFED'));
        $this->assertSame('SHN', TaskLists::suggestPrefix('شركة النور'));
        $c1 = Client::factory()->create(['name' => 'ULFED']);
        $c2 = Client::factory()->create(['name' => 'Ulfa Group']);
        $this->assertSame('ULF', TaskLists::prefixFor($c1));
        $this->assertSame('ULF2', TaskLists::prefixFor($c2));

        // The prefix can be set on the client form.
        $u = $this->userWith(['crm.view' => 'all', 'crm.edit' => 'all']);
        $this->actingAs($u)->put("/crm/clients/{$c2->id}", ['name' => $c2->name, 'type' => 'company', 'currency' => 'USD', 'task_prefix' => 'ulg'])->assertSessionHasNoErrors();
        $this->assertSame('ULG', $c2->fresh()->task_prefix);
        $this->actingAs($u)->put("/crm/clients/{$c2->id}", ['name' => $c2->name, 'type' => 'company', 'currency' => 'USD', 'task_prefix' => 'ULF'])->assertSessionHasErrors('task_prefix');
    }

    public function test_subtasks_up_to_three_levels_and_progress(): void
    {
        $u = $this->full();
        $list = $this->list();
        $root = $this->task($list, $u);
        $this->actingAs($u)->post('/tasks', ['title' => 'فرعية', 'parent_id' => $root->id])->assertSessionHasNoErrors();
        $child = Task::where('parent_id', $root->id)->first();
        $this->assertSame(1, $child->depth);
        $this->assertSame($list->id, $child->task_list_id);
        $this->actingAs($u)->post('/tasks', ['title' => 'فرعية ثانية', 'parent_id' => $child->id])->assertSessionHasNoErrors();
        $grand = Task::where('parent_id', $child->id)->first();
        $this->assertSame(2, $grand->depth);
        $this->actingAs($u)->post('/tasks', ['title' => 'رابع مستوى', 'parent_id' => $grand->id])->assertSessionHasErrors('parent_id');

        // Progress of the parent counts subtasks and checklist items.
        $done = $list->statuses()->where('group', 'done')->first();
        $this->actingAs($u)->patch("/tasks/{$child->id}", ['status_id' => $done->id])->assertSessionHasNoErrors();
        $this->actingAs($u)->post("/tasks/{$root->id}/checklists", ['name' => 'قبل التسليم'])->assertSessionHasNoErrors();
        $cl = $root->checklists()->first();
        $this->actingAs($u)->post("/tasks/checklists/{$cl->id}/items", ['text' => 'مراجعة الألوان'])->assertSessionHasNoErrors();
        $item = $cl->items()->first();
        $this->actingAs($u)->post("/tasks/checklists/{$cl->id}/items", ['text' => 'فرعي', 'parent_id' => $item->id])->assertSessionHasNoErrors();
        $this->assertSame(['done' => 1, 'total' => 3, 'percent' => 33], Tasks::progress($root->fresh()));
        // Checking a parent item checks its children.
        $this->actingAs($u)->patch("/tasks/checklist-items/{$item->id}", ['done' => true])->assertSessionHasNoErrors();
        $this->assertSame(['done' => 3, 'total' => 3, 'percent' => 100], Tasks::progress($root->fresh()));

        $this->actingAs($u)->get("/tasks/{$root->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('tasks/Show')
                ->has('task.children', 1)->has('task.children.0.children', 1)->has('task.checklists.0.items', 2)
                ->where('task.progress.percent', 100)->where('can.edit', true));

        // Archiving the root archives its subtasks.
        $this->actingAs($u)->delete("/tasks/{$root->id}")->assertRedirect();
        $this->assertSame(0, Task::whereIn('id', [$root->id, $child->id, $grand->id])->count());
        $this->actingAs($u)->post("/tasks/{$root->id}/restore")->assertRedirect("/tasks/{$root->id}");
        $this->assertSame(3, Task::whereIn('id', [$root->id, $child->id, $grand->id])->count());
    }

    public function test_status_groups_drive_completion_and_milestone_progress(): void
    {
        $u = $this->full();
        $project = Project::factory()->create();
        $m = $project->milestones()->create(['title' => 'المطبوعات', 'status' => 'pending']);
        $list = TaskLists::create($project->client, $project, ['name' => 'المطبوعات', 'project_milestone_id' => $m->id], $u->id, false);
        $a = $this->task($list, $u);
        $b = $this->task($list, $u);
        $this->assertSame($m->id, $a->project_milestone_id);
        $this->assertSame(0, $m->fresh()->progress());

        $approved = $list->statuses()->where('name', 'معتمد')->first();
        $published = $list->statuses()->where('name', 'منشور')->first();
        $review = $list->statuses()->where('name', 'مراجعة داخلية')->first();
        $this->actingAs($u)->patch("/tasks/{$a->id}", ['status_id' => $approved->id])->assertSessionHasNoErrors();
        $this->assertNotNull($a->fresh()->completed_at);
        $this->assertSame(50, $m->fresh()->progress());
        $this->assertSame(50, $project->fresh()->progress());
        $this->assertSame('pending', $m->fresh()->status);

        $this->actingAs($u)->patch("/tasks/{$b->id}", ['status_id' => $published->id])->assertSessionHasNoErrors();
        $this->assertSame('done', $m->fresh()->status);
        $this->assertSame(100, $project->fresh()->progress());

        // Reopening a task reopens the milestone.
        $this->actingAs($u)->patch("/tasks/{$b->id}", ['status_id' => $review->id])->assertSessionHasNoErrors();
        $this->assertNull($b->fresh()->completed_at);
        $this->assertSame('pending', $m->fresh()->status);

        // The status change is in the task's activity history.
        $this->assertTrue(ActivityLog::where('subject_type', 'task')->where('subject_id', $b->id)->where('description', 'like', '%مراجعة داخلية%')->exists());

        // The project page shows milestone progress and the task lists.
        $this->actingAs($u)->get("/projects/{$project->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('milestones.0.progress', 50)->where('milestones.0.tasks_total', 2)
                ->has('taskLists', 1)->where('taskLists.0.done', 1));
    }

    public function test_statuses_are_per_list_and_removed_ones_move_their_tasks(): void
    {
        $u = $this->full();
        $l1 = $this->list();
        $l2 = $this->list($l1->client, null, 'ريلز');
        $t = $this->task($l1, $u);
        $statuses = $l1->statuses()->get();
        $brief = $statuses->firstWhere('name', 'موجز');
        $this->assertSame($brief->id, $t->status_id);

        // Remove "موجز", rename one, add a new one; move "موجز" tasks to the new status.
        $rows = $statuses->reject(fn ($s) => $s->name === 'موجز')->map(fn ($s) => $s->only('id', 'name', 'color', 'group'))->values()->all();
        $rows[0]['name'] = 'جارٍ العمل';
        $rows[] = ['id' => null, 'name' => 'فكرة', 'color' => '#123456', 'group' => 'not_started'];
        $this->actingAs($u)->put("/tasks/lists/{$l1->id}/statuses", ['statuses' => $rows, 'move_to' => [$brief->id => 'new:'.(count($rows) - 1)]])
            ->assertSessionHasNoErrors();
        $this->assertSame('فكرة', $t->fresh()->status->name);
        $this->assertSame(7, $l1->statuses()->count());
        // The other list is untouched.
        $this->assertSame('موجز', $l2->statuses()->first()->name);

        // A scheme needs a complete status.
        $this->actingAs($u)->put("/tasks/lists/{$l1->id}/statuses", ['statuses' => [['name' => 'x', 'group' => 'active']]])->assertSessionHasErrors('statuses');
    }

    public function test_task_can_appear_in_several_lists_and_moves_only_within_its_client(): void
    {
        $u = $this->full();
        $l1 = $this->list();
        $shoots = $this->list(TaskLists::internalClient(), null, 'تصوير الأسبوع');
        $t = $this->task($l1, $u);

        $this->actingAs($u)->post("/tasks/{$t->id}/lists", ['task_list_id' => $shoots->id])->assertSessionHasNoErrors();
        $this->actingAs($u)->get("/tasks?list={$shoots->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('tasks', 1)->where('tasks.0.id', $t->id)->where('location.name', 'تصوير الأسبوع'));
        $this->actingAs($u)->get("/tasks?list={$l1->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->has('tasks', 1));

        // Moving to another client's list is refused (the id belongs to the client).
        $this->actingAs($u)->post("/tasks/{$t->id}/move", ['task_list_id' => $shoots->id])->assertSessionHasErrors('task_list_id');
        $l2 = $this->list($l1->client, null, 'ريلز');
        $this->actingAs($u)->post("/tasks/{$t->id}/move", ['task_list_id' => $l2->id])->assertSessionHasNoErrors();
        $this->assertSame($l2->id, $t->fresh()->task_list_id);
        $this->assertSame('ULF-1', $t->fresh()->code());

        $this->actingAs($u)->delete("/tasks/{$t->id}/lists/{$shoots->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, $t->otherLists()->count());
    }

    public function test_people_tags_dates_and_estimate(): void
    {
        $u = $this->full();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $list = $this->list();
        $t = $this->task($list, $u);
        $tag = Tag::create(['name' => 'تصميم', 'color' => '#ff0000']);

        $this->actingAs($u)->patch("/tasks/{$t->id}", [
            'assignees' => [$a->id, $b->id], 'watchers' => [$u->id], 'tags' => [$tag->id], 'priority' => 'urgent',
            'start_date' => '2026-12-01', 'due_date' => '2026-12-03', 'due_time' => '14:30', 'time_estimate_min' => 150,
        ])->assertSessionHasNoErrors();
        $t->refresh();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $t->assignees->pluck('id')->all());
        // New assignees follow the task.
        $this->assertEqualsCanonicalizing([$u->id, $a->id, $b->id], $t->watchers->pluck('id')->all());
        $this->assertSame(['تصميم'], $t->tags->pluck('name')->all());
        $this->assertSame('2026-12-03 14:30', $t->due_at->format('Y-m-d H:i'));
        $this->assertTrue($t->due_has_time);
        $this->assertFalse($t->start_has_time);
        $this->assertSame(150, $t->time_estimate_min);

        $this->actingAs($u)->patch("/tasks/{$t->id}", ['start_date' => '2026-12-05', 'due_date' => '2026-12-03'])->assertSessionHasErrors('due_date');

        // Assigning needs tasks.assign.
        $editor = $this->userWith(['tasks.view' => 'all', 'tasks.edit' => 'all']);
        $this->actingAs($editor)->patch("/tasks/{$t->id}", ['assignees' => [$editor->id]])->assertForbidden();
        $this->actingAs($editor)->patch("/tasks/{$t->id}", ['title' => 'عنوان جديد'])->assertSessionHasNoErrors();
        $this->assertSame('عنوان جديد', $t->fresh()->title);

        // Filters: by assignee and priority.
        $this->actingAs($u)->get("/tasks?assignees[]={$a->id}&priorities[]=urgent")->assertOk()->assertInertia(fn (Assert $page) => $page->has('tasks', 1));
        $this->actingAs($u)->get('/tasks?assignees[]=me')->assertOk()->assertInertia(fn (Assert $page) => $page->has('tasks', 0));
    }

    public function test_scopes_limit_what_people_see_and_edit(): void
    {
        $boss = $this->full();
        $project = Project::factory()->create();
        $list = TaskLists::create($project->client, $project, ['name' => 'عام'], null, false);
        $mine = User::factory()->create();
        $mine->syncPermissions(['tasks.view' => 'own', 'tasks.edit' => 'own', 'tasks.create' => 'own']);
        $member = User::factory()->create();
        $member->syncPermissions(['tasks.view' => 'assigned', 'tasks.edit' => 'assigned']);
        $project->members()->attach($member->id);

        $assigned = $this->task($list, $boss, ['assignees' => [$mine->id]]);
        $other = $this->task($list, $boss, ['assignees' => [$boss->id]]);

        $this->actingAs($mine)->get('/tasks')->assertOk()->assertInertia(fn (Assert $page) => $page->has('tasks', 1)->where('tasks.0.id', $assigned->id));
        $this->actingAs($mine)->get("/tasks/{$other->id}")->assertForbidden();
        $this->actingAs($mine)->patch("/tasks/{$other->id}", ['title' => 'x'])->assertForbidden();
        $this->actingAs($mine)->get("/tasks/{$assigned->id}")->assertOk();
        // The list is visible because it holds a visible task.
        $this->actingAs($mine)->get("/tasks?list={$list->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->has('tasks', 1));
        // Can't add tasks to a project list without seeing it, but the internal list is open to everyone.
        $this->actingAs($mine)->post('/tasks', ['title' => 'داخلية'])->assertSessionHasNoErrors();
        $this->assertSame('INS-1', Task::latest('id')->first()->code());

        // Members of the project see all of its tasks.
        $this->actingAs($member)->get("/tasks?project={$project->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->has('tasks', 2));
        $this->actingAs($member)->get("/tasks/{$other->id}")->assertOk();

        $this->actingAs($this->userWith())->get('/tasks')->assertForbidden();
    }

    public function test_lists_are_managed_by_project_manager_or_full_editors(): void
    {
        $pm = $this->userWith(['tasks.view' => 'assigned', 'tasks.edit' => 'assigned', 'tasks.create' => 'assigned', 'projects.view' => 'assigned']);
        $project = Project::factory()->create(['manager_id' => $pm->id]);
        $this->actingAs($pm)->post('/tasks/lists', ['name' => 'ديسمبر', 'project_id' => $project->id])->assertRedirect();
        $list = TaskList::where('name', 'ديسمبر')->first();
        $this->assertSame($project->client_id, $list->client_id);
        $this->actingAs($pm)->put("/tasks/lists/{$list->id}", ['name' => 'ديسمبر 2026'])->assertSessionHasNoErrors();
        $this->assertSame('ديسمبر 2026', $list->fresh()->name);

        $stranger = $this->userWith(['tasks.view' => 'assigned', 'tasks.edit' => 'assigned']);
        $this->actingAs($stranger)->put("/tasks/lists/{$list->id}", ['name' => 'x'])->assertForbidden();
        $this->actingAs($stranger)->post('/tasks/lists', ['name' => 'x', 'project_id' => $project->id])->assertForbidden();

        // A list with tasks cannot be deleted.
        Tasks::create($list, ['title' => 'منشور'], $pm);
        $this->actingAs($pm)->delete("/tasks/lists/{$list->id}")->assertSessionHasErrors('list');
    }

    public function test_index_and_settings_pages_render(): void
    {
        $u = $this->full(['settings.manage' => 'all']);
        $list = $this->list();
        $t = $this->task($list, $u, ['due_date' => today()->subDays(2)->toDateString()]);

        $this->actingAs($u)->get('/tasks')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('tasks/Index')->has('tree', 1)->where('tree.0.prefix', 'ULF')
                ->has('tasks', 1)->where('tasks.0.overdue', true)->where('can.create', true));
        $this->actingAs($u)->get('/tasks?due=overdue')->assertOk()->assertInertia(fn (Assert $page) => $page->has('tasks', 1));
        $this->actingAs($u)->get("/tasks?client={$list->client_id}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('location.prefix', 'ULF'));

        $this->actingAs($u)->post('/tasks/tags', ['name' => 'عاجل للعميل'])->assertSessionHasNoErrors();
        $this->actingAs($u)->get('/settings/tasks')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('settings/Tasks')->has('templates', 2)->has('tags', 1)->where('internal.task_prefix', 'INS'));

        $tpl = StatusScheme::defaultTemplate();
        $rows = $tpl->statuses->map(fn ($s) => $s->only('id', 'name', 'color', 'group'))->all();
        $rows[] = ['name' => 'مؤرشف', 'color' => '#000000', 'group' => 'closed'];
        $this->actingAs($u)->put("/settings/status-templates/{$tpl->id}", ['statuses' => $rows])->assertSessionHasNoErrors();
        $this->assertSame(8, $tpl->statuses()->count());
        // Existing lists keep their statuses; new ones get the new template.
        $this->assertSame(7, $list->statuses()->count());
        $this->assertSame(8, $this->list($list->client, null, 'جديدة')->statuses()->count());
    }
}

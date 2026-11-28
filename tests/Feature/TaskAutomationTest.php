<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Client;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\Tasks\Automations;
use App\Services\Tasks\Checklists;
use App\Services\Tasks\CustomFields;
use App\Services\Tasks\TaskLists;
use App\Services\Tasks\Tasks;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\NumberSequenceSeeder;
use Database\Seeders\TaskSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TaskAutomationTest extends TestCase
{
    use RefreshDatabase;

    private User $u;

    private Client $client;

    private TaskList $list;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, NumberSequenceSeeder::class, TaskSeeder::class]);
        $this->u = $this->userWith(['tasks.view' => 'all', 'tasks.create' => 'all', 'tasks.edit' => 'all', 'tasks.assign' => 'all', 'projects.view' => 'all']);
        $this->actingAs($this->u);
        $this->client = Client::factory()->create(['name' => 'ULFED']);
        $this->list = TaskLists::create($this->client, null, ['name' => 'المعرض'], null, false);
    }

    private function task(array $d = [], ?TaskList $list = null): Task
    {
        return Tasks::create($list ?? $this->list, $d + ['title' => 'مهمة '.uniqid()], $this->u);
    }

    private function statusId(Task $t, string $name): int
    {
        return $t->list->statuses()->where('name', $name)->value('id');
    }

    private function automation(array $d): Automation
    {
        $this->post('/tasks/automations', $d + ['name' => 'أتمتة '.uniqid(), 'scope_type' => 'all'])->assertSessionHasNoErrors();

        return Automation::latest('id')->first();
    }

    private function automationNotices(User $u): int
    {
        return $u->notifications()->where('data->kind', 'task.automation')->count();
    }

    public function test_task_templates_keep_structure_and_relative_dates(): void
    {
        $this->travelTo('2026-12-01 10:00');
        $field = CustomField::create(['name' => 'المنصة', 'type' => 'text', 'scope_type' => 'all']);
        $booth = $this->task(['title' => 'تصميم الجناح', 'start_date' => '2026-12-02', 'due_date' => '2026-12-04', 'priority' => 'high']);
        CustomFields::setValue($booth, $field, 'STK');
        $this->task(['title' => 'المخطط', 'parent_id' => $booth->id, 'due_date' => '2026-12-03']);
        $c = Checklists::create($booth, 'التسليم');
        $parent = Checklists::addItem($c, ['text' => 'ملفات الطباعة']);
        Checklists::addItem($c, ['text' => 'PDF', 'parent_id' => $parent->id]);

        $this->post('/tasks/templates', ['from' => 'task', 'id' => $booth->id, 'name' => 'جناح معرض'])->assertSessionHasNoErrors();
        $tpl = TaskTemplate::firstOrFail();
        $this->assertSame(['start_in_days' => 1, 'due_in_days' => 3], array_intersect_key($tpl->payload['task'], ['start_in_days' => 1, 'due_in_days' => 1]));

        $this->travelTo('2026-12-10 09:00');
        $this->post("/tasks/templates/{$tpl->id}/apply", ['list_id' => $this->list->id])->assertRedirect();
        $copy = Task::where('title', 'تصميم الجناح')->latest('id')->first();
        $this->assertNotSame($booth->id, $copy->id);
        $this->assertSame('2026-12-13', $copy->due_at->toDateString());
        $this->assertSame('2026-12-11', $copy->start_at->toDateString());
        $this->assertSame('high', $copy->priority);
        $this->assertSame('STK', $copy->fieldValues()->first()->value);
        $this->assertSame('2026-12-12', $copy->children()->first()->due_at->toDateString());
        $items = $copy->checklists()->first()->items()->get();
        $this->assertCount(2, $items);
        $this->assertSame($items->firstWhere('text', 'ملفات الطباعة')->id, $items->firstWhere('text', 'PDF')->parent_id);

        // As a subtask of another task.
        $host = $this->task(['title' => 'المعرض']);
        $this->post("/tasks/templates/{$tpl->id}/apply", ['parent_id' => $host->id])->assertRedirect();
        $this->assertSame('تصميم الجناح', $host->children()->first()->title);
    }

    public function test_list_and_project_templates(): void
    {
        $project = Project::factory()->create(['client_id' => $this->client->id]);
        $source = TaskLists::create($this->client, $project, ['name' => 'محتوى نوفمبر'], null, false);
        TaskLists::saveStatuses($source->scheme, [['name' => 'فكرة', 'group' => 'not_started'], ['name' => 'جاهز', 'group' => 'done']]);
        $this->task(['title' => 'بوست 1', 'status_id' => $source->statuses()->where('name', 'جاهز')->value('id')], $source->fresh());
        $this->task(['title' => 'بوست 2'], $source->fresh());

        $this->post('/tasks/templates', ['from' => 'list', 'id' => $source->id, 'name' => 'شهر محتوى'])->assertSessionHasNoErrors();
        $this->post('/tasks/templates', ['from' => 'project', 'id' => $project->id, 'name' => 'مشروع محتوى'])->assertSessionHasNoErrors();
        [$listTpl, $projectTpl] = [TaskTemplate::where('type', 'list')->first(), TaskTemplate::where('type', 'project')->first()];

        $this->post("/tasks/templates/{$listTpl->id}/apply", ['project_id' => $project->id, 'name' => 'محتوى ديسمبر'])->assertRedirect();
        $dec = TaskList::where('name', 'محتوى ديسمبر')->firstOrFail();
        $this->assertSame(['فكرة', 'جاهز'], $dec->statuses()->pluck('name')->all());
        $this->assertSame(['بوست 1', 'بوست 2'], Task::where('task_list_id', $dec->id)->orderBy('position')->pluck('title')->all());
        $this->assertSame('جاهز', Task::where('task_list_id', $dec->id)->where('title', 'بوست 1')->first()->status->name);

        $other = Project::factory()->create(['client_id' => $this->client->id]);
        $this->post("/tasks/templates/{$projectTpl->id}/apply", ['project_id' => $other->id])->assertRedirect();
        $this->assertSame(['محتوى نوفمبر'], $other->taskLists()->pluck('name')->all());

        // Only the owner (or settings managers) may delete a template.
        $this->actingAs($this->userWith(['tasks.view' => 'all']))->delete("/tasks/templates/{$listTpl->id}")->assertForbidden();
        $this->actingAs($this->u)->delete("/tasks/templates/{$listTpl->id}")->assertSessionHasNoErrors();
        $this->assertSame(1, TaskTemplate::count());
    }

    public function test_status_and_field_automations(): void
    {
        $cd = $this->userWith(['tasks.view' => 'all']);
        $rounds = CustomField::create(['name' => 'جولات التعديل', 'type' => 'number', 'scope_type' => 'all']);
        $this->automation(['trigger' => 'status_changed', 'trigger_config' => ['to' => 'مراجعة داخلية'],
            'actions' => [['type' => 'create_subtask', 'title' => 'مراجعة إبداعية: {title}', 'users' => [$cd->id], 'due_in_days' => 1]]]);
        $this->automation(['trigger' => 'status_changed', 'trigger_config' => ['to' => 'تعديلات'], 'actions' => [['type' => 'increment_field', 'field_id' => $rounds->id, 'by' => 1]]]);
        $this->automation(['trigger' => 'field_changed', 'trigger_config' => ['field_id' => $rounds->id],
            'conditions' => [['field' => "field:{$rounds->id}", 'op' => 'gt', 'value' => 2]],
            'actions' => [['type' => 'notify', 'users' => ['creator'], 'message' => 'تجاوزت الجولات: {code}']]]);

        $t = $this->task(['title' => 'فيديو المعرض']);
        Tasks::update($t, ['status_id' => $this->statusId($t, 'مراجعة داخلية')]);
        $review = $t->children()->first();
        $this->assertSame('مراجعة إبداعية: فيديو المعرض', $review->title);
        $this->assertSame([$cd->id], $review->assignees()->pluck('users.id')->all());

        foreach (range(1, 3) as $i) {
            Tasks::update($t->fresh(), ['status_id' => $this->statusId($t, 'تعديلات')]);
            Tasks::update($t->fresh(), ['status_id' => $this->statusId($t, 'قيد التنفيذ')]);
        }
        $this->assertEquals(3, $t->fieldValues()->where('custom_field_id', $rounds->id)->value('value'));
        $this->assertSame(1, $this->automationNotices($this->u)); // only once the third round passes the limit
        $this->assertSame(1, Automation::where('trigger', 'field_changed')->first()->runs_count); // conditions not met do not count as runs

        // A failing action is logged, not thrown.
        $bad = $this->automation(['trigger' => 'comment_added', 'actions' => [['type' => 'set_status', 'status' => 'غير موجودة']]]);
        $this->post("/tasks/{$t->id}/comments", ['body' => json_encode(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'ملاحظة']]]]])]);
        $this->assertSame('error', AutomationRun::where('automation_id', $bad->id)->value('status'));

        $this->get('/tasks/automations')->assertOk()->assertInertia(fn (Assert $page) => $page->component('tasks/Automations')->has('automations', 4)->has('recipes', 6));
    }

    public function test_automations_cannot_loop_forever(): void
    {
        $this->automation(['trigger' => 'status_changed', 'trigger_config' => ['to' => 'تعديلات'], 'actions' => [['type' => 'set_status', 'status' => 'مراجعة داخلية']]]);
        $this->automation(['trigger' => 'status_changed', 'trigger_config' => ['to' => 'مراجعة داخلية'], 'actions' => [['type' => 'set_status', 'status' => 'تعديلات']]]);
        $t = $this->task();
        Tasks::update($t, ['status_id' => $this->statusId($t, 'تعديلات')]);
        $this->assertLessThanOrEqual(3, AutomationRun::count());
        $this->assertTrue(AutomationRun::where('status', 'ok')->exists());
    }

    public function test_time_based_and_monthly_automations(): void
    {
        $this->travelTo('2026-11-30 09:00');
        $late = $this->task(['title' => 'الطباعة', 'due_date' => '2026-11-28']);
        $this->task(['title' => 'في الموعد', 'due_date' => '2026-12-05']);
        $this->automation(['trigger' => 'overdue', 'scope_type' => 'list', 'scope_id' => $this->list->id,
            'actions' => [['type' => 'notify', 'users' => ['assignees'], 'message' => 'تجاوزت موعدها: {title}']]]);
        $this->assertSame(1, Automations::tick());
        $this->assertSame(0, Automations::tick()); // not twice for the same due date
        $this->assertSame(1, $this->automationNotices($this->u));
        Tasks::update($late, ['due_date' => '2026-11-29']);
        $this->assertSame(1, Automations::tick()); // a new (still late) due date counts again

        // Monthly: the content list of the month from a list template, once, on the first day.
        $project = Project::factory()->create(['client_id' => $this->client->id]);
        $src = TaskLists::create($this->client, $project, ['name' => 'قالب'], null, false);
        $this->task(['title' => '8 منشورات'], $src);
        $tpl = \App\Services\Tasks\Templates::fromList($src, 'عقد شهري', $this->u);
        $this->automation(['trigger' => 'monthly', 'scope_type' => 'project', 'scope_id' => $project->id,
            'actions' => [['type' => 'apply_template', 'template_id' => $tpl->id, 'list_name' => 'محتوى {month}']]]);
        $before = $project->taskLists()->count();
        $this->travelTo('2026-12-01 00:15');
        Automations::tick();
        Automations::tick();
        $this->assertSame($before + 1, $project->taskLists()->count());
        $new = TaskList::where('project_id', $project->id)->latest('id')->first();
        $this->assertStringStartsWith('محتوى ', $new->name);
        $this->assertSame(['8 منشورات'], Task::where('task_list_id', $new->id)->pluck('title')->all());
        $this->travelTo('2026-12-02 00:15');
        Automations::tick();
        $this->assertSame($before + 1, $project->taskLists()->count());
    }

    public function test_who_may_manage_automations_and_validation(): void
    {
        $viewer = $this->userWith(['tasks.view' => 'all']);
        $this->actingAs($viewer)->get('/tasks/automations')->assertForbidden();
        $this->actingAs($viewer)->post('/tasks/automations', ['name' => 'س', 'scope_type' => 'all', 'trigger' => 'overdue', 'actions' => [['type' => 'notify']]])->assertForbidden();

        $this->actingAs($this->u)->post('/tasks/automations', ['name' => 'س', 'scope_type' => 'all', 'trigger' => 'monthly', 'actions' => [['type' => 'set_status', 'status' => 'x']]])
            ->assertSessionHasErrors('actions');
        $a = $this->automation(['trigger' => 'overdue', 'actions' => [['type' => 'notify', 'users' => ['assignees']]]]);
        $this->put("/tasks/automations/{$a->id}", ['is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($a->fresh()->is_active);
        $this->delete("/tasks/automations/{$a->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, Automation::count());
    }
}

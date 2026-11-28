<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\SavedView;
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

class TaskViewsTest extends TestCase
{
    use RefreshDatabase;

    private User $u;

    private TaskList $list;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, NumberSequenceSeeder::class, TaskSeeder::class]);
        $this->u = $this->userWith([
            'tasks.view' => 'all', 'tasks.create' => 'all', 'tasks.edit' => 'all', 'tasks.delete' => 'all', 'tasks.assign' => 'all',
        ]);
        $this->actingAs($this->u);
        $this->list = TaskLists::create(Client::factory()->create(['name' => 'ULFED']), null, ['name' => 'المطبوعات'], null, false);
    }

    private function task(array $data = [], ?TaskList $list = null): Task
    {
        return Tasks::create($list ?? $this->list, $data + ['title' => 'مهمة '.uniqid()], $this->u);
    }

    public function test_view_settings_sorting_and_subtasks(): void
    {
        $a = $this->task(['title' => 'ب', 'priority' => 'low', 'due_date' => '2026-12-20']);
        $b = $this->task(['title' => 'أ', 'priority' => 'urgent', 'due_date' => '2026-12-10']);
        $this->task(['title' => 'فرعية', 'parent_id' => $a->id]);

        $this->get('/tasks?view=table&sort=priority&columns[]=status&columns[]=bogus')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('view.view', 'table')->where('view.group', 'none')
                ->where('view.columns', ['status'])->where('tasks.0.id', $b->id)->has('tasks', 3));
        $this->get('/tasks?view=board&sort=due&dir=desc&subtasks=0')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('view.view', 'board')->has('tasks', 2)->where('tasks.0.id', $a->id));
        $this->get('/tasks?view=nonsense')->assertOk()->assertInertia(fn (Assert $page) => $page->where('view.view', 'list')->where('view.group', 'status'));
    }

    public function test_saved_views_private_and_shared(): void
    {
        $this->task(['priority' => 'urgent']);
        $this->task(['priority' => 'low']);

        $this->post('/tasks/views', [
            'name' => 'العاجل', 'type' => 'board', 'location_type' => 'list', 'location_id' => $this->list->id, 'is_shared' => false,
            'config' => ['group' => 'priority', 'sort' => 'due', 'filters' => ['priorities' => ['urgent']]],
        ])->assertRedirect();
        $v = SavedView::first();
        $this->assertSame('board', $v->type);
        $this->assertSame('priority', $v->config['group']);
        $this->assertSame(['priorities' => ['urgent']], $v->config['filters']);

        // Opening it applies its settings and filters.
        $this->get("/tasks?list={$this->list->id}&saved={$v->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('view.view', 'board')->where('view.group', 'priority')
                ->where('filters.priorities', ['urgent'])->has('tasks', 1)->where('savedView.id', $v->id)->has('savedViews', 1));
        // It shows only in its list.
        $other = TaskLists::create($this->list->client, null, ['name' => 'أخرى'], null, false);
        $this->get("/tasks?list={$other->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->has('savedViews', 0));

        // Private: a colleague neither sees nor changes it; shared: they see it but cannot change it.
        $colleague = $this->userWith(['tasks.view' => 'all', 'tasks.edit' => 'assigned']);
        $this->actingAs($colleague)->get("/tasks?list={$this->list->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->has('savedViews', 0));
        $this->actingAs($this->u)->put("/tasks/views/{$v->id}", ['is_shared' => true])->assertRedirect();
        $this->actingAs($colleague)->get("/tasks?list={$this->list->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('savedViews', 1)->where('savedViews.0.mine', false));
        $this->actingAs($colleague)->put("/tasks/views/{$v->id}", ['name' => 'x'])->assertForbidden();
        $this->actingAs($colleague)->delete("/tasks/views/{$v->id}")->assertForbidden();

        // Company-wide views show everywhere.
        $this->actingAs($this->u)->post('/tasks/views', ['name' => 'مهامي', 'type' => 'list', 'config' => ['filters' => ['assignees' => ['me']]]])->assertRedirect();
        $this->get("/tasks?list={$other->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->has('savedViews', 1)->where('savedViews.0.name', 'مهامي'));

        $this->put("/tasks/views/{$v->id}", ['replace_config' => true, 'type' => 'table', 'config' => ['columns' => ['due', 'tags']]])->assertRedirect();
        $this->assertSame(['due', 'tags'], $v->fresh()->config['columns']);
        $this->assertSame('table', $v->fresh()->type);
        $this->delete("/tasks/views/{$v->id}")->assertRedirect();
        $this->assertNull($v->fresh());
    }

    public function test_bulk_actions_skip_what_the_user_cannot_change(): void
    {
        $a = $this->task();
        $b = $this->task();
        $c = $this->task();
        $designer = User::factory()->create();
        $done = $this->list->statuses()->where('group', 'done')->first();

        $this->post('/tasks/bulk', ['ids' => [$a->id, $b->id], 'action' => 'status', 'value' => $done->id])->assertSessionHas('success');
        $this->assertNotNull($a->fresh()->completed_at);
        $this->assertNotNull($b->fresh()->completed_at);

        $this->post('/tasks/bulk', ['ids' => [$a->id, $b->id, $c->id], 'action' => 'assignees_add', 'value' => [$designer->id]])->assertSessionHas('success');
        $this->assertEqualsCanonicalizing([$this->u->id, $designer->id], $c->assignees()->pluck('users.id')->all());

        $this->post('/tasks/bulk', ['ids' => [$a->id, $c->id], 'action' => 'due', 'value' => ['date' => '2026-12-24']])->assertSessionHas('success');
        $this->assertSame('2026-12-24', $c->fresh()->due_at->toDateString());
        $this->post('/tasks/bulk', ['ids' => [$a->id], 'action' => 'priority', 'value' => 'urgent']);
        $this->assertSame('urgent', $a->fresh()->priority);

        // Moving: tasks of another client are skipped.
        $internal = TaskLists::create(TaskLists::internalClient(), null, ['name' => 'داخلي'], null, false);
        $x = $this->task([], $internal);
        $same = TaskLists::create($this->list->client, null, ['name' => 'ريلز'], null, false);
        $this->post('/tasks/bulk', ['ids' => [$a->id, $x->id], 'action' => 'move', 'value' => $same->id])->assertSessionHas('success');
        $this->assertSame($same->id, $a->fresh()->task_list_id);
        $this->assertSame($internal->id, $x->fresh()->task_list_id);

        // Someone with "own" scope only changes their own tasks.
        $own = $this->userWith(['tasks.view' => 'own', 'tasks.edit' => 'own']);
        $mine = Tasks::create($this->list, ['title' => 'لي', 'assignees' => [$own->id]], $this->u);
        $this->actingAs($own)->post('/tasks/bulk', ['ids' => [$mine->id, $c->id], 'action' => 'priority', 'value' => 'high'])->assertSessionHas('success');
        $this->assertSame('high', $mine->fresh()->priority);
        $this->assertSame('normal', $c->fresh()->priority);
        $this->actingAs($own)->post('/tasks/bulk', ['ids' => [$mine->id], 'action' => 'archive'])->assertSessionHas('error');
        $this->assertNotNull($mine->fresh());

        $this->actingAs($this->u)->post('/tasks/bulk', ['ids' => [$b->id, $c->id], 'action' => 'archive'])->assertSessionHas('success');
        $this->assertNull(Task::find($b->id));
        $this->assertNull(Task::find($c->id));
    }

    public function test_export_csv(): void
    {
        $t = $this->task(['title' => 'رول أب', 'due_date' => '2026-12-05', 'due_time' => '09:30', 'time_estimate_min' => 90]);
        $res = $this->get('/tasks/export?list='.$this->list->id)->assertOk();
        $csv = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('ULF-1', $csv);
        $this->assertStringContainsString('رول أب', $csv);
        $this->assertStringContainsString('2026-12-05 09:30', $csv);
        $this->assertStringContainsString('1.5', $csv);

        $this->actingAs($this->userWith(['tasks.view' => 'own']))->get('/tasks/export')->assertOk();
        $this->actingAs($this->userWith())->get('/tasks/export')->assertForbidden();
    }
}

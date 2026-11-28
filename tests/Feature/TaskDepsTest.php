<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\TaskList;
use App\Models\User;
use App\Services\Tasks\Recurrence;
use App\Services\Tasks\TaskLists;
use App\Services\Tasks\Tasks;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\NumberSequenceSeeder;
use Database\Seeders\TaskSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TaskDepsTest extends TestCase
{
    use RefreshDatabase;

    private User $u;

    private TaskList $list;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, NumberSequenceSeeder::class, TaskSeeder::class]);
        $this->u = $this->userWith(['tasks.view' => 'all', 'tasks.create' => 'all', 'tasks.edit' => 'all', 'tasks.assign' => 'all']);
        $this->actingAs($this->u);
        $this->list = TaskLists::create(Client::factory()->create(['name' => 'ULFED']), null, ['name' => 'المعرض'], null, false);
    }

    private function task(array $d = []): Task
    {
        return Tasks::create($this->list, $d + ['title' => 'مهمة '.uniqid()], $this->u);
    }

    public function test_dependencies_links_and_cycles(): void
    {
        $design = $this->task(['title' => 'التصميم']);
        $print = $this->task(['title' => 'الطباعة']);
        $install = $this->task(['title' => 'التركيب']);

        $this->post("/tasks/{$print->id}/dependencies", ['other_id' => $design->id, 'type' => 'waiting'])->assertSessionHasNoErrors();
        $this->post("/tasks/{$print->id}/dependencies", ['other_id' => $install->id, 'type' => 'blocking'])->assertSessionHasNoErrors();
        $this->post("/tasks/{$design->id}/dependencies", ['other_id' => $install->id, 'type' => 'waiting'])->assertSessionHasErrors('other_id'); // cycle
        $this->post("/tasks/{$design->id}/dependencies", ['other_id' => $design->id, 'type' => 'link'])->assertSessionHasErrors('other_id');
        $this->post("/tasks/{$design->id}/dependencies", ['other_id' => $install->id, 'type' => 'link'])->assertSessionHasNoErrors();
        $this->post("/tasks/{$install->id}/dependencies", ['other_id' => $design->id, 'type' => 'link'])->assertSessionHasNoErrors(); // same link, not duplicated
        $this->assertSame(3, TaskDependency::count());

        $this->get("/tasks/{$print->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('dependencies.waiting_on.0.id', $design->id)->where('dependencies.blocking.0.id', $install->id)->has('dependencies.links', 0));
        $this->get("/tasks/{$install->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->has('dependencies.links', 1)->where('dependencies.links.0.id', $design->id));
        $this->get('/tasks?view=gantt')->assertOk()->assertInertia(fn (Assert $page) => $page->where('view.view', 'gantt')
            ->where('tasks.1.waiting_on', [$design->id])->where('tasks.1.blocked', true)->where('tasks.0.blocked', false));

        // Closing a task that still waits needs a confirmation.
        $done = $this->list->statuses()->where('group', 'done')->first();
        $this->patch("/tasks/{$print->id}", ['status_id' => $done->id])->assertSessionHasErrors('blocked');
        $this->assertNull($print->fresh()->completed_at);
        $this->patch("/tasks/{$print->id}", ['status_id' => $done->id, 'force' => true])->assertSessionHasNoErrors();
        $this->assertNotNull($print->fresh()->completed_at);

        $dep = TaskDependency::where('task_id', $print->id)->first();
        $this->delete("/tasks/dependencies/{$dep->id}")->assertSessionHasNoErrors();
        $this->assertSame(2, TaskDependency::count());

        $this->getJson('/tasks/lookup?q=الطب')->assertOk()->assertJsonPath('0.id', $print->id);
        $this->getJson('/tasks/lookup?q=ULF-1')->assertOk()->assertJsonCount(1)->assertJsonPath('0.code', 'ULF-1');
    }

    public function test_moving_a_due_date_moves_what_waits_on_it(): void
    {
        $a = $this->task(['due_date' => '2026-12-10']);
        $b = $this->task(['start_date' => '2026-12-11', 'due_date' => '2026-12-12']);
        $c = $this->task(['due_date' => '2026-12-15', 'due_time' => '10:00']);
        $doneLater = $this->task(['due_date' => '2026-12-20']);
        $this->post("/tasks/{$b->id}/dependencies", ['other_id' => $a->id, 'type' => 'waiting']);
        $this->post("/tasks/{$c->id}/dependencies", ['other_id' => $b->id, 'type' => 'waiting']);
        $this->post("/tasks/{$doneLater->id}/dependencies", ['other_id' => $a->id, 'type' => 'waiting']);
        $closed = $this->list->statuses()->where('group', 'closed')->first();
        $this->patch("/tasks/{$doneLater->id}", ['status_id' => $closed->id, 'force' => true]);

        $this->patch("/tasks/{$a->id}", ['due_date' => '2026-12-12'])->assertSessionHasNoErrors();
        $this->assertSame('2026-12-13', $b->fresh()->start_at->toDateString());
        $this->assertSame('2026-12-14', $b->fresh()->due_at->toDateString());
        $this->assertSame('2026-12-17 10:00', $c->fresh()->due_at->format('Y-m-d H:i'));
        $this->assertSame('2026-12-20', $doneLater->fresh()->due_at->toDateString()); // complete: not moved
    }

    public function test_next_occurrence_rules(): void
    {
        $mon = Carbon::parse('2026-12-07'); // Monday
        $this->assertSame('2026-12-10', Recurrence::next($mon, ['freq' => 'weekly', 'interval' => 1, 'weekdays' => [1, 4]])->toDateString());
        $this->assertSame('2026-12-14', Recurrence::next(Carbon::parse('2026-12-10'), ['freq' => 'weekly', 'interval' => 1, 'weekdays' => [1, 4]])->toDateString());
        $this->assertSame('2026-12-21', Recurrence::next(Carbon::parse('2026-12-10'), ['freq' => 'weekly', 'interval' => 2, 'weekdays' => [1, 4]])->toDateString());
        $this->assertSame('2027-02-28', Recurrence::next(Carbon::parse('2027-01-31'), ['freq' => 'monthly', 'interval' => 1])->toDateString());
        $this->assertSame('2026-12-10', Recurrence::next($mon, ['freq' => 'daily', 'interval' => 3])->toDateString());
        $this->assertSame('أسبوعياً (الاثنين، الخميس)، عند الإغلاق', Recurrence::label(Recurrence::clean(['freq' => 'weekly', 'weekdays' => [4, 1]])));
    }

    public function test_completing_a_recurring_task_creates_the_next_one(): void
    {
        $this->travelTo(Carbon::parse('2026-12-01 09:00'));
        $designer = User::factory()->create();
        $t = $this->task(['title' => 'تقرير الأداء الشهري', 'due_date' => '2026-12-05', 'assignees' => [$designer->id]]);
        $this->patch("/tasks/{$t->id}", ['recurrence' => ['freq' => 'monthly', 'interval' => 1, 'on' => 'complete']])->assertSessionHasNoErrors();
        $this->post("/tasks/{$t->id}/checklists", ['name' => 'خطوات']);
        $cl = $t->checklists()->first();
        $this->post("/tasks/checklists/{$cl->id}/items", ['text' => 'جمع الأرقام']);
        $this->patch('/tasks/checklist-items/'.$cl->items()->first()->id, ['done' => true]);
        $this->post('/tasks', ['title' => 'رسم بياني', 'parent_id' => $t->id]);

        $this->get('/tasks')->assertOk()->assertInertia(fn (Assert $page) => $page->where('tasks.0.recurrence_label', 'شهرياً، عند الإغلاق'));

        $done = $this->list->statuses()->where('group', 'done')->first();
        $this->patch("/tasks/{$t->id}", ['status_id' => $done->id])->assertSessionHasNoErrors();
        $next = Task::where('recurred_from_id', $t->id)->first();
        $this->assertNotNull($next);
        $this->assertSame('2027-01-05', $next->due_at->toDateString());
        $this->assertSame('تقرير الأداء الشهري', $next->title);
        $this->assertNull($next->completed_at);
        $this->assertSame([$designer->id], $next->assignees()->pluck('users.id')->all());
        $this->assertFalse($next->checklists()->first()->items()->first()->done);
        $this->assertSame(1, $next->children()->count());
        $this->assertSame('monthly', $next->recurrence['freq']);
        $this->assertNull($t->fresh()->recurrence);
        $this->assertNotSame($t->seq, $next->seq);
    }

    public function test_scheduled_recurrence_and_end_date(): void
    {
        $this->travelTo(Carbon::parse('2026-12-01 09:00'));
        $t = $this->task(['title' => 'نشر أسبوعي', 'due_date' => '2026-12-03']);
        $this->patch("/tasks/{$t->id}", ['recurrence' => ['freq' => 'weekly', 'on' => 'schedule', 'until' => '2026-12-12']]);

        $this->artisan('tasks:recurring')->assertSuccessful();
        $this->assertSame(0, Task::whereNotNull('recurred_from_id')->count()); // not due yet

        $this->travelTo(Carbon::parse('2026-12-03 00:20'));
        $this->artisan('tasks:recurring')->assertSuccessful();
        $second = Task::where('recurred_from_id', $t->id)->first();
        $this->assertSame('2026-12-10', $second->due_at->toDateString());
        $this->assertNull($t->fresh()->completed_at); // created even though the first is still open

        $this->travelTo(Carbon::parse('2026-12-10 00:20'));
        $this->artisan('tasks:recurring')->assertSuccessful();
        $this->assertSame(0, Task::where('recurred_from_id', $second->id)->count()); // next would be after "until"
        $this->assertNull($second->fresh()->recurrence);
    }
}

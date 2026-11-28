<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Comment;
use App\Models\CrmActivity;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\TaskList;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Tasks\Comments;
use App\Services\Tasks\TaskLists;
use App\Services\Tasks\Tasks;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\NumberSequenceSeeder;
use Database\Seeders\TaskSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MyWorkTest extends TestCase
{
    use RefreshDatabase;

    private User $u;

    private TaskList $list;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, NumberSequenceSeeder::class, TaskSeeder::class]);
        $this->u = $this->userWith([
            'tasks.view' => 'all', 'tasks.create' => 'all', 'tasks.edit' => 'all', 'tasks.assign' => 'all', 'tasks.track_time' => 'own',
            'projects.view' => 'all', 'crm.view' => 'all',
        ]);
        $this->u->forceFill(['hourly_cost' => 20])->save();
        $this->actingAs($this->u);
        $this->list = TaskLists::create(Client::factory()->create(['name' => 'ULFED']), null, ['name' => 'المطبوعات'], null, false);
    }

    public function test_timer_runs_on_the_server_one_at_a_time(): void
    {
        $a = Tasks::create($this->list, ['title' => 'أ'], $this->u);
        $internal = Tasks::create(TaskLists::create(TaskLists::internalClient(), null, ['name' => 'داخلي'], null, false), ['title' => 'اجتماع'], $this->u);

        $this->post('/timer/start', ['task_id' => $a->id])->assertSessionHasNoErrors();
        $this->get("/tasks/{$a->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('timer.task.id', $a->id)->where('can.track', true));

        $this->travel(30)->minutes();
        // Starting another timer stops the first one.
        $this->post('/timer/start', ['task_id' => $internal->id])->assertSessionHasNoErrors();
        $first = TimeEntry::where('task_id', $a->id)->first();
        $this->assertSame(30, $first->minutes);
        $this->assertTrue($first->billable); // client work
        $this->assertSame('20.00', $first->cost_rate);
        $this->assertSame($a->client_id, $first->client_id);
        $this->assertSame(1, TimeEntry::running()->count());

        $this->travel(45)->minutes();
        $this->post('/timer/stop')->assertSessionHasNoErrors();
        $second = TimeEntry::where('task_id', $internal->id)->first();
        $this->assertSame(45, $second->minutes);
        $this->assertFalse($second->billable); // internal work
        $this->assertSame(0, TimeEntry::running()->count());

        // Less than a minute is discarded.
        $this->post('/timer/start', ['task_id' => $a->id]);
        $this->post('/timer/stop');
        $this->assertSame(2, TimeEntry::count());

        $this->get("/tasks/{$a->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('time.total', 30)->has('time.entries', 1)->where('timer', null));
    }

    public function test_manual_entries_and_project_cost(): void
    {
        $project = Project::factory()->create(['budget' => 1000]);
        $list = TaskLists::create($project->client, $project, ['name' => 'عام'], null, false);
        $t = Tasks::create($list, ['title' => 'تصوير'], $this->u);

        $this->post('/time-entries', ['task_id' => $t->id, 'date' => today()->toDateString(), 'minutes' => 90])->assertSessionHasNoErrors();
        $this->post('/time-entries', ['task_id' => $t->id, 'date' => today()->toDateString(), 'from' => '10:00', 'to' => '10:30', 'billable' => false])->assertSessionHasNoErrors();
        $this->post('/time-entries', ['task_id' => $t->id, 'date' => today()->addDays(3)->toDateString(), 'minutes' => 30])->assertSessionHasErrors('date');
        $this->post('/time-entries', ['task_id' => $t->id, 'date' => today()->toDateString(), 'from' => '11:00', 'to' => '10:00'])->assertSessionHasErrors('to');
        $this->assertSame(2, TimeEntry::count());

        // Someone else's entry needs the "all" scope.
        $other = User::factory()->create();
        $this->post('/time-entries', ['user_id' => $other->id, 'date' => today()->toDateString(), 'minutes' => 10])->assertForbidden();
        $e = TimeEntry::first();
        $this->actingAs($other);
        $other->syncPermissions(['tasks.track_time' => 'own']);
        $this->patch("/time-entries/{$e->id}", ['minutes' => 5])->assertForbidden();
        $this->actingAs($this->u);
        $this->patch("/time-entries/{$e->id}", ['minutes' => 60])->assertSessionHasNoErrors();
        $this->assertSame(60, $e->fresh()->minutes);

        // Project page: hours for the team, cost only for people who see the budget.
        $this->get("/projects/{$project->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('project.time.minutes', 90)->where('project.time.billable_minutes', 60)->where('project.time.cost', null));
        $boss = $this->userWith(['projects.view' => 'all', 'projects.manage_budget' => 'all']);
        $this->actingAs($boss)->get("/projects/{$project->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('project.time.cost', 30)); // 1.5h x 20

        $this->actingAs($this->u)->get('/my-work/time')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('tasks/MyTime')->has('entries', 2)->where('totals.minutes', 90)->where('seeCost', false));
    }

    public function test_hourly_cost_is_salary_data(): void
    {
        $admin = $this->userWith(['users.view' => 'all', 'users.manage' => 'all']);
        $target = User::factory()->create(['hourly_cost' => 15]);
        $payload = ['name' => $target->name, 'email' => $target->email, 'is_active' => true, 'permissions' => [], 'hourly_cost' => 99];

        $this->actingAs($admin)->get("/admin/users/{$target->id}/edit")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('user.hourly_cost', null)->where('canCost', false));
        $this->actingAs($admin)->put("/admin/users/{$target->id}", $payload)->assertSessionHasNoErrors();
        $this->assertSame('15.00', $target->fresh()->hourly_cost);

        $hr = $this->userWith(['users.view' => 'all', 'users.manage' => 'all', 'hr.view_salaries' => 'all']);
        $this->actingAs($hr)->put("/admin/users/{$target->id}", $payload)->assertSessionHasNoErrors();
        $this->assertSame('99.00', $target->fresh()->hourly_cost);
        $this->assertArrayNotHasKey('hourly_cost', $target->fresh()->toArray());
    }

    public function test_reminders_and_morning_digest(): void
    {
        $t = Tasks::create($this->list, ['title' => 'متابعة المطبعة', 'due_date' => today()->toDateString()], $this->u);
        $this->post('/reminders', ['task_id' => $t->id, 'remind_at' => now()->addHour()->format('Y-m-d H:i'), 'note' => 'اتصل بالمطبعة'])->assertSessionHasNoErrors();
        $this->post('/reminders', ['remind_at' => now()->subDay()->format('Y-m-d H:i')])->assertSessionHasErrors('remind_at');
        $r = Reminder::first();

        $this->artisan('tasks:reminders')->assertSuccessful();
        $this->assertSame(0, $this->u->notifications()->count());
        $this->travel(61)->minutes();
        $this->artisan('tasks:reminders')->assertSuccessful();
        $n = $this->u->notifications()->first();
        $this->assertSame('task.reminder', $n->data['kind']);
        $this->assertStringContainsString('اتصل بالمطبعة', $n->data['title']);
        $this->assertNotNull($r->fresh()->sent_at);
        $this->artisan('tasks:reminders');
        $this->assertSame(1, $this->u->notifications()->count());

        $this->artisan('tasks:digest')->assertSuccessful();
        $this->artisan('tasks:digest')->assertSuccessful();
        $digests = $this->u->notifications()->get()->where('data.kind', 'task.digest');
        $this->assertCount(1, $digests);
        $this->assertStringContainsString('1 مهمة اليوم', $digests->first()->data['title']);

        $this->patch("/reminders/{$r->id}", ['done' => true])->assertSessionHasNoErrors();
        $this->assertNotNull($r->fresh()->done_at);
        $this->actingAs(User::factory()->create())->delete("/reminders/{$r->id}")->assertForbidden();
    }

    public function test_my_work_inbox_and_calendar(): void
    {
        $designer = User::factory()->create();
        $designer->syncPermissions(['tasks.view' => 'own']);
        Tasks::create($this->list, ['title' => 'متأخرة', 'due_date' => today()->subDays(2)->toDateString()], $this->u);
        Tasks::create($this->list, ['title' => 'اليوم', 'due_date' => today()->toDateString()], $this->u);
        Tasks::create($this->list, ['title' => 'قريباً', 'due_date' => today()->addDays(3)->toDateString()], $this->u);
        Tasks::create($this->list, ['title' => 'بلا تاريخ'], $this->u);
        Tasks::create($this->list, ['title' => 'لغيري', 'assignees' => [$designer->id], 'due_date' => today()->toDateString()], $this->u);
        $t = Tasks::create($this->list, ['title' => 'مراجعة', 'assignees' => [$designer->id]], $this->u);
        Comments::add($t, $this->u, 'راجع النص', null, $this->u->id); // assigned to myself: still listed
        $this->actingAs($designer);
        Comments::add($t, $designer, 'جاهز للمراجعة', null, $this->u->id);
        $this->actingAs($this->u);
        CrmActivity::create(['subject_type' => 'client', 'subject_id' => $this->list->client_id, 'type' => 'meeting', 'title' => 'اجتماع ULFED',
            'due_at' => now()->addHours(2), 'user_id' => $this->u->id, 'created_by' => $this->u->id]);

        $this->get('/my-work')->assertOk()->assertInertia(fn (Assert $page) => $page->component('tasks/MyWork')
            ->has('overdue', 1)->has('today', 1)->has('upcoming', 1)->has('noDate', 1)->has('comments', 2)
            ->where('overdue.0.title', 'متأخرة')->has('listStatuses.'.$this->list->id, 7));

        // Inbox: the designer's comment notified me.
        $this->get('/inbox')->assertOk()->assertInertia(fn (Assert $page) => $page->component('tasks/Inbox')->where('counts.unread', 1)->has('items.data', 1));
        $id = $this->u->notifications()->first()->id;
        $this->post('/notifications/read', ['ids' => [$id]]);
        $this->get('/inbox?unread=1')->assertOk()->assertInertia(fn (Assert $page) => $page->has('items.data', 0));
        $this->post('/notifications/unread', ['ids' => [$id]]);
        $this->assertSame(1, $this->u->unreadNotifications()->count());
        $this->get('/inbox?kind=task.comment_assigned')->assertOk()->assertInertia(fn (Assert $page) => $page->has('items.data', 1));
        $this->post('/notifications/read', ['ids' => [$id]]);
        $this->delete('/notifications', ['read_only' => true]);
        $this->assertSame(0, $this->u->notifications()->count());

        $this->get('/my-work/calendar')->assertOk()->assertInertia(fn (Assert $page) => $page->component('tasks/MyCalendar')
            ->has('tasks', 3)->has('appointments', 1)->where('appointments.0.title', '📅 اجتماع ULFED'));
    }
}

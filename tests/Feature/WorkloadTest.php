<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\Timesheet;
use App\Models\TimeEntry;
use App\Models\TimeOff;
use App\Models\User;
use App\Services\Tasks\TaskLists;
use App\Services\Tasks\Tasks;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\NumberSequenceSeeder;
use Database\Seeders\TaskSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, NumberSequenceSeeder::class, TaskSeeder::class]);
        $this->travelTo(Carbon::parse('2026-12-07 10:00')); // Monday; the week runs Sat 5 → Fri 11
    }

    public function test_hours_against_capacity_with_time_off(): void
    {
        $boss = $this->userWith(['tasks.view' => 'all', 'tasks.create' => 'all', 'tasks.edit' => 'all', 'tasks.assign' => 'all']);
        $a = $this->userWith(['tasks.view' => 'own', 'tasks.track_time' => 'own']);
        $b = $this->userWith(['tasks.view' => 'own']);
        $this->actingAs($boss);
        $list = TaskLists::create(Client::factory()->create(), null, ['name' => 'عام'], null, false);
        Tasks::create($list, ['title' => 'تصميم الهوية', 'assignees' => [$a->id, $b->id], 'start_date' => '2026-12-07', 'due_date' => '2026-12-09', 'time_estimate_min' => 600], $boss);
        Tasks::create($list, ['title' => 'مونتاج', 'assignees' => [$a->id], 'due_date' => '2026-12-10', 'time_estimate_min' => 600], $boss);
        Tasks::create($list, ['title' => 'بلا تقدير', 'assignees' => [$a->id], 'due_date' => '2026-12-11'], $boss);
        TimeOff::create(['user_id' => $a->id, 'starts_on' => '2026-12-11', 'ends_on' => '2026-12-11', 'type' => 'sick']);

        $props = $this->get('/tasks/workload')->assertOk()->viewData('page')['props'];
        $this->assertSame('tasks/Workload', $this->get('/tasks/workload')->viewData('page')['component']);
        $this->assertSame('2026-12-05', $props['days'][0]);
        $this->assertCount(7, $props['days']);
        $p = collect($props['people'])->firstWhere('id', $a->id);
        $this->assertEquals(1.67, $p['cells']['2026-12-07']['hours']);
        $this->assertEquals(10, $p['cells']['2026-12-10']['hours']);
        $this->assertEquals(8, $p['cells']['2026-12-10']['capacity']);
        $this->assertEquals(0, $p['cells']['2026-12-11']['capacity']);
        $this->assertSame('sick', $p['cells']['2026-12-11']['off']);
        $this->assertEquals(0, $p['cells']['2026-12-05']['capacity']);
        $this->assertEquals(15, $p['total']);
        $this->assertEquals(32, $p['capacity']);
        $this->assertFalse($p['over']);
        $this->assertEquals(0, $p['tasks'][2]['hours']);

        // Working only 4 hours a day puts the person over capacity.
        $this->put("/tasks/workload/capacity/{$a->id}", ['capacity_hours' => 3, 'work_days' => [1, 2, 3, 4, 5]])->assertSessionHasNoErrors();
        $props = $this->get('/tasks/workload?mode=timeline')->assertOk()->viewData('page')['props'];
        $this->assertSame('timeline', $props['mode']);
        $this->assertTrue(collect($props['people'])->firstWhere('id', $a->id)['over']);

        // An employee sees only themselves and cannot change capacity or others' days off.
        $this->actingAs($a)->get('/tasks/workload')->assertOk()->assertInertia(fn (Assert $page) => $page->has('people', 1)->where('canManage', false));
        $this->actingAs($a)->put("/tasks/workload/capacity/{$a->id}", ['capacity_hours' => 8, 'work_days' => [1]])->assertForbidden();
        $this->actingAs($a)->post('/tasks/time-off', ['user_id' => $a->id, 'starts_on' => '2026-12-20', 'ends_on' => '2026-12-22', 'type' => 'vacation'])->assertSessionHasNoErrors();
        $this->actingAs($a)->post('/tasks/time-off', ['user_id' => $b->id, 'starts_on' => '2026-12-20', 'ends_on' => '2026-12-22', 'type' => 'vacation'])->assertForbidden();
        $this->actingAs($a)->get('/tasks/workload?week=2026-12-19&weeks=2')->assertOk()->assertInertia(fn (Assert $page) => $page->has('days', 14)->has('timeOff', 1));
    }

    public function test_timesheets_submit_lock_and_approve(): void
    {
        $emp = $this->userWith(['tasks.view' => 'own', 'tasks.track_time' => 'own']);
        $approver = $this->userWith(['tasks.view' => 'all', 'tasks.approve_time' => 'all']);
        $pm = $this->userWith(['tasks.view' => 'assigned', 'tasks.approve_time' => 'assigned']);
        $other = $this->userWith(['tasks.view' => 'own', 'tasks.track_time' => 'own']);
        $project = Project::factory()->create(['manager_id' => $pm->id]);
        $project->members()->attach($emp->id);

        $this->actingAs($emp)->post('/time-entries', ['date' => '2026-12-07', 'minutes' => 120])->assertSessionHasNoErrors();
        $this->actingAs($emp)->post('/timesheets/submit', ['week' => '2026-12-07'])->assertSessionHasNoErrors();
        $ts = Timesheet::first();
        $this->assertSame('submitted', $ts->status);
        $this->assertSame('2026-12-05', $ts->week_start->toDateString());
        $this->assertSame(1, $approver->notifications()->count());
        $this->assertSame(1, $pm->notifications()->count());
        $this->assertSame(0, $other->notifications()->count());

        // Locked: no new, changed or deleted time in that week.
        $this->actingAs($emp)->post('/time-entries', ['date' => '2026-12-06', 'minutes' => 30])->assertSessionHasErrors('date');
        $e = TimeEntry::first();
        $this->actingAs($emp)->patch("/time-entries/{$e->id}", ['minutes' => 30])->assertSessionHasErrors('date');
        $this->actingAs($emp)->delete("/time-entries/{$e->id}")->assertSessionHasErrors('date');
        $this->actingAs($emp)->post('/time-entries', ['date' => '2026-12-04', 'minutes' => 30])->assertSessionHasNoErrors(); // previous week is open
        $this->actingAs($emp)->get('/my-work/time?week=2026-12-07')->assertOk()->assertInertia(fn (Assert $page) => $page->where('timesheet.status', 'submitted')->where('timesheet.locked', true));

        // Who may decide.
        $this->actingAs($other)->post("/timesheets/{$ts->id}/decide", ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($approver)->get('/tasks/timesheets')->assertOk()->assertInertia(fn (Assert $page) => $page->has('sheets', 1)->where('sheets.0.totals.minutes', 120));
        $this->actingAs($pm)->get('/tasks/timesheets')->assertOk()->assertInertia(fn (Assert $page) => $page->has('sheets', 1));
        $this->actingAs($pm)->post("/timesheets/{$ts->id}/decide", ['decision' => 'reject', 'note' => 'ناقص يوم الثلاثاء'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $ts->fresh()->status);
        $this->assertSame('timesheet.reject', $emp->notifications()->first()->data['kind']);

        // Rejected weeks are open again; resubmit and approve.
        $this->actingAs($emp)->post('/time-entries', ['date' => '2026-12-06', 'minutes' => 60])->assertSessionHasNoErrors();
        $this->actingAs($emp)->post('/timesheets/submit', ['week' => '2026-12-09'])->assertSessionHasNoErrors();
        $this->actingAs($approver)->post("/timesheets/{$ts->id}/decide", ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $ts->fresh()->status);
        $this->actingAs($approver)->post("/timesheets/{$ts->id}/decide", ['decision' => 'approve'])->assertSessionHasErrors('status');
        $this->actingAs($approver)->post("/timesheets/{$ts->id}/decide", ['decision' => 'reopen'])->assertSessionHasNoErrors();
        $this->assertSame('draft', $ts->fresh()->status);
        $this->assertSame(1, Timesheet::count());

        // Nobody approves their own week.
        $approver->syncPermissions(['tasks.view' => 'all', 'tasks.approve_time' => 'all', 'tasks.track_time' => 'own']);
        $this->actingAs($approver)->post('/timesheets/submit', ['week' => '2026-12-07'])->assertSessionHasNoErrors();
        $own = Timesheet::where('user_id', $approver->id)->first();
        $this->actingAs($approver)->post("/timesheets/{$own->id}/decide", ['decision' => 'approve'])->assertForbidden();
    }
}

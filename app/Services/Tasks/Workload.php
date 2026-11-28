<?php

namespace App\Services\Tasks;

use App\Models\Task;
use App\Models\TimeOff;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Estimated hours per person per day against their capacity.
 *
 * An open task's estimate is split equally between its assignees, then spread evenly over the person's
 * working days between the task's start and due dates (only the due day when there is no start). A day
 * off or a non-working day has no capacity. Tasks without an estimate count as tasks, not hours.
 */
class Workload
{
    /**
     * @param  Collection<int, User>  $users
     * @return array{days: list<string>, people: list<array<string, mixed>>}
     */
    public static function build(Collection $users, Carbon $from, Carbon $to, ?int $projectId = null): array
    {
        $days = [];
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            $days[] = $d->toDateString();
        }
        $ids = $users->pluck('id');
        $tasks = Task::query()->open()->with(['client:id,task_prefix', 'status'])
            ->whereIn('tasks.id', DB::table('task_assignees')->select('task_id')->whereIn('user_id', $ids))
            ->when($projectId, fn ($q) => $q->where('tasks.project_id', $projectId))
            ->whereNotNull('tasks.due_at')
            ->where('tasks.due_at', '>=', $from->copy()->startOfDay())
            ->where(fn ($q) => $q->where('tasks.start_at', '<=', $to->copy()->endOfDay())->orWhere(fn ($w) => $w->whereNull('tasks.start_at')->where('tasks.due_at', '<=', $to->copy()->endOfDay())))
            ->get();
        $assignees = DB::table('task_assignees')->whereIn('task_id', $tasks->pluck('id'))->get()->groupBy('task_id');
        $off = TimeOff::whereIn('user_id', $ids)->where('starts_on', '<=', $to->toDateString())->where('ends_on', '>=', $from->toDateString())->get()->groupBy('user_id');

        $people = [];
        foreach ($users as $u) {
            $workDays = $u->workDays();
            $offDays = [];
            foreach ($off[$u->id] ?? [] as $o) {
                for ($d = $o->starts_on->copy(); $d->lte($o->ends_on); $d->addDay()) {
                    $offDays[$d->toDateString()] = $o->type;
                }
            }
            $cells = [];
            foreach ($days as $day) {
                $dow = Carbon::parse($day)->dayOfWeek;
                $cells[$day] = [
                    'hours' => 0.0, 'tasks' => [],
                    'capacity' => isset($offDays[$day]) || ! in_array($dow, $workDays, true) ? 0.0 : (float) $u->capacity_hours,
                    'off' => $offDays[$day] ?? null,
                ];
            }
            $rows = [];
            foreach ($tasks as $t) {
                $who = $assignees[$t->id] ?? collect();
                if (! $who->contains('user_id', $u->id)) {
                    continue;
                }
                $span = self::span($t, $workDays);
                $share = $t->time_estimate_min ? $t->time_estimate_min / 60 / max(1, $who->count()) : 0;
                $perDay = $share / max(1, count($span));
                foreach ($span as $day) {
                    if (isset($cells[$day])) {
                        $cells[$day]['hours'] += $perDay;
                        $cells[$day]['tasks'][] = $t->id;
                    }
                }
                $rows[$t->id] = [
                    'id' => $t->id, 'code' => $t->code(), 'title' => $t->title, 'status' => $t->status?->toUi(),
                    'start' => TaskPresenter::date($t->start_at, $t->start_has_time), 'due' => TaskPresenter::date($t->due_at, $t->due_has_time),
                    'hours' => round($share, 2), 'overdue' => $t->isOverdue(),
                ];
            }
            $total = array_sum(array_column($cells, 'hours'));
            foreach ($cells as &$c) {
                $c['hours'] = round($c['hours'], 2);
            }
            unset($c);
            $cap = array_sum(array_column($cells, 'capacity'));
            $people[] = [
                'id' => $u->id, 'name' => $u->name, 'capacity_hours' => (float) $u->capacity_hours, 'work_days' => $workDays,
                'cells' => $cells, 'tasks' => array_values($rows), 'total' => round($total, 2), 'capacity' => round($cap, 2),
                'over' => $total > $cap + 0.01,
            ];
        }

        return ['days' => $days, 'people' => $people];
    }

    /** The person's working days from the task's start to its due date (or the due day alone). @return list<string> */
    public static function span(Task $t, array $workDays): array
    {
        $end = $t->due_at->copy()->startOfDay();
        $start = $t->start_at && $t->start_at->lte($t->due_at) ? $t->start_at->copy()->startOfDay() : $end->copy();
        $out = [];
        for ($d = $start->copy(), $i = 0; $d->lte($end) && $i < 370; $d->addDay(), $i++) {
            if (in_array($d->dayOfWeek, $workDays, true)) {
                $out[] = $d->toDateString();
            }
        }

        return $out ?: [$end->toDateString()];
    }
}

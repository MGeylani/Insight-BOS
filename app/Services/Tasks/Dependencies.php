<?php

namespace App\Services\Tasks;

use App\Models\Task;
use App\Models\TaskDependency;
use App\Services\ActivityLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Waiting on" / "blocking" dependencies (finish-to-start) and plain links between tasks.
 * A task cannot wait on itself or on a task that (directly or not) waits on it.
 */
class Dependencies
{
    /** @param string $type waiting (task waits on other) | blocking (other waits on task) | link */
    public static function add(Task $task, Task $other, string $type, ?int $userId): TaskDependency
    {
        if ($task->id === $other->id) {
            throw ValidationException::withMessages(['other_id' => 'لا تُربط المهمة بنفسها.']);
        }
        [$waiter, $blocker, $kind] = match ($type) {
            'waiting' => [$task, $other, 'waiting'],
            'blocking' => [$other, $task, 'waiting'],
            default => [$task, $other, 'link'],
        };
        if ($kind === 'waiting' && in_array($waiter->id, self::upstreamIds($blocker), true)) {
            throw ValidationException::withMessages(['other_id' => 'هذا يصنع حلقة: '.$blocker->code().' تنتظر '.$waiter->code().' أصلاً.']);
        }
        $exists = TaskDependency::where('type', $kind)->where(fn ($q) => $q
            ->where(fn ($w) => $w->where('task_id', $waiter->id)->where('depends_on_id', $blocker->id))
            ->when($kind === 'link', fn ($w) => $w->orWhere(fn ($x) => $x->where('task_id', $blocker->id)->where('depends_on_id', $waiter->id))))->first();
        if ($exists) {
            return $exists;
        }
        $dep = TaskDependency::create(['task_id' => $waiter->id, 'depends_on_id' => $blocker->id, 'type' => $kind, 'created_by' => $userId]);
        ActivityLogger::log('tasks.dependency', $waiter, $kind === 'waiting'
            ? "أصبحت {$waiter->code()} بانتظار {$blocker->code()}: {$blocker->title}"
            : "ربط {$waiter->code()} بـ {$blocker->code()}");

        return $dep;
    }

    public static function remove(TaskDependency $dep): void
    {
        $dep->delete();
        if ($dep->task) {
            ActivityLogger::log('tasks.dependency', $dep->task, 'إزالة اعتمادية/ربط مع '.$dep->dependsOn?->code());
        }
    }

    /** Open tasks this one waits on. */
    public static function openBlockers(Task $task): Collection
    {
        return Task::whereIn('id', TaskDependency::where('task_id', $task->id)->where('type', 'waiting')->select('depends_on_id'))
            ->whereNull('completed_at')->get();
    }

    /**
     * When a blocker's due date moves, move the dates of everything downstream by the same amount
     * (keeps the gaps the team planned). Each task moves once.
     */
    public static function shiftDependents(Task $task, int $minutes, array &$seen = []): int
    {
        if ($minutes === 0) {
            return 0;
        }
        $seen[$task->id] = true;
        $moved = 0;
        $dependents = Task::whereIn('id', TaskDependency::where('depends_on_id', $task->id)->where('type', 'waiting')->select('task_id'))
            ->whereNull('completed_at')->get();
        foreach ($dependents as $d) {
            if (isset($seen[$d->id]) || (! $d->start_at && ! $d->due_at)) {
                continue;
            }
            $seen[$d->id] = true;
            $d->start_at = $d->start_at?->copy()->addMinutes($minutes);
            $d->due_at = $d->due_at?->copy()->addMinutes($minutes);
            $d->saveQuietly();
            $days = round($minutes / 1440, 1);
            ActivityLogger::log('tasks.rescheduled', $d, 'تحرّك موعدها '.($days > 0 ? "$days يوم للأمام" : abs($days).' يوم للخلف').' مع '.$task->code());
            $moved++;
            $moved += self::shiftDependents($d, $minutes, $seen);
        }

        return $moved;
    }

    /** Ids of everything this task waits on, directly or not. @return list<int> */
    public static function upstreamIds(Task $task): array
    {
        $all = [];
        $level = [$task->id];
        for ($i = 0; $i < 50 && $level; $i++) {
            $level = TaskDependency::whereIn('task_id', $level)->where('type', 'waiting')->pluck('depends_on_id')->diff($all)->all();
            $all = array_merge($all, $level);
        }

        return array_map('intval', $all);
    }

    /**
     * Dependencies of many tasks for the views: waiting_on ids (any status) and whether an open blocker exists.
     *
     * @return array<int, array{waiting_on: list<int>, blocked: bool}>
     */
    public static function forTasks(Collection $ids): array
    {
        if ($ids->isEmpty()) {
            return [];
        }
        $rows = DB::table('task_dependencies as d')->join('tasks as b', 'b.id', '=', 'd.depends_on_id')
            ->whereIn('d.task_id', $ids)->where('d.type', 'waiting')->whereNull('b.deleted_at')
            ->get(['d.task_id', 'd.depends_on_id', 'b.completed_at']);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->task_id]['waiting_on'][] = (int) $r->depends_on_id;
            $out[$r->task_id]['blocked'] = ($out[$r->task_id]['blocked'] ?? false) || $r->completed_at === null;
        }

        return $out;
    }

    /** All relations of one task for its page. @return array<string, list<array<string, mixed>>> */
    public static function payload(Task $task): array
    {
        $row = fn (TaskDependency $d, Task $t) => [
            'dep_id' => $d->id, 'id' => $t->id, 'code' => $t->code(), 'title' => $t->title, 'completed' => $t->completed_at !== null,
            'due' => TaskPresenter::date($t->due_at, $t->due_has_time),
        ];
        $deps = TaskDependency::with(['task.client:id,task_prefix', 'dependsOn.client:id,task_prefix'])
            ->where(fn ($q) => $q->where('task_id', $task->id)->orWhere('depends_on_id', $task->id))->get()
            ->filter(fn ($d) => $d->task && $d->dependsOn && ! $d->task->trashed() && ! $d->dependsOn->trashed());

        return [
            'waiting_on' => $deps->where('type', 'waiting')->where('task_id', $task->id)->map(fn ($d) => $row($d, $d->dependsOn))->values()->all(),
            'blocking' => $deps->where('type', 'waiting')->where('depends_on_id', $task->id)->map(fn ($d) => $row($d, $d->task))->values()->all(),
            'links' => $deps->where('type', 'link')->map(fn ($d) => $row($d, (int) $d->task_id === $task->id ? $d->dependsOn : $d->task))->values()->all(),
        ];
    }
}

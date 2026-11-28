<?php

namespace App\Services\Tasks;

use App\Models\Checklist;
use App\Models\CustomFieldValue;
use App\Models\Task;
use App\Services\ActivityLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recurring tasks. The rule lives on the current instance; the next instance is a copy (same list,
 * people, tags, type, fields, checklists unticked, subtasks) with its dates moved to the next occurrence,
 * and the rule moves to it. Created when the task is completed (on = complete) or when its due date
 * arrives, completed or not (on = schedule, by `tasks:recurring` daily).
 */
class Recurrence
{
    public const FREQS = ['daily', 'weekly', 'monthly', 'yearly'];

    /** @return array<string, mixed>|null */
    public static function clean(?array $r): ?array
    {
        if (! $r || empty($r['freq'])) {
            return null;
        }
        if (! in_array($r['freq'], self::FREQS, true)) {
            throw ValidationException::withMessages(['recurrence' => 'تكرار غير معروف.']);
        }
        $weekdays = array_values(array_unique(array_filter(array_map('intval', (array) ($r['weekdays'] ?? [])), fn ($d) => $d >= 0 && $d <= 6)));
        sort($weekdays);

        return array_filter([
            'freq' => $r['freq'],
            'interval' => max(1, min(365, (int) ($r['interval'] ?? 1))),
            'weekdays' => $r['freq'] === 'weekly' ? $weekdays : null,
            'on' => ($r['on'] ?? 'complete') === 'schedule' ? 'schedule' : 'complete',
            'until' => ! empty($r['until']) ? Carbon::parse($r['until'])->toDateString() : null,
        ], fn ($v) => $v !== null && $v !== []);
    }

    public static function label(?array $r): string
    {
        if (! $r) {
            return '';
        }
        $n = (int) ($r['interval'] ?? 1);
        $days = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
        $base = match ($r['freq']) {
            'daily' => $n > 1 ? "كل $n أيام" : 'يومياً',
            'weekly' => ($n > 1 ? "كل $n أسابيع" : 'أسبوعياً').(! empty($r['weekdays']) ? ' ('.implode('، ', array_map(fn ($d) => $days[$d], $r['weekdays'])).')' : ''),
            'monthly' => $n > 1 ? "كل $n أشهر" : 'شهرياً',
            'yearly' => 'سنوياً',
            default => '',
        };

        return $base.(($r['on'] ?? 'complete') === 'schedule' ? '، بالجدول' : '، عند الإغلاق').(! empty($r['until']) ? " حتى {$r['until']}" : '');
    }

    /** Next occurrence after $from. */
    public static function next(Carbon $from, array $r): Carbon
    {
        $n = (int) ($r['interval'] ?? 1);

        return match ($r['freq']) {
            'daily' => $from->copy()->addDays($n),
            'weekly' => self::nextWeekly($from, $n, $r['weekdays'] ?? []),
            'monthly' => $from->copy()->addMonthsNoOverflow($n),
            'yearly' => $from->copy()->addYearsNoOverflow($n),
        };
    }

    /** The next listed weekday this week, else the first listed weekday (interval - 1) weeks later. */
    private static function nextWeekly(Carbon $from, int $n, array $weekdays): Carbon
    {
        if (! $weekdays) {
            return $from->copy()->addWeeks($n);
        }
        $dow = $from->dayOfWeek;
        foreach ($weekdays as $wd) {
            if ($wd > $dow) {
                return $from->copy()->addDays($wd - $dow);
            }
        }

        return $from->copy()->addDays(7 - $dow + $weekdays[0])->addWeeks($n - 1);
    }

    /** Create the next instance; returns null when the rule has ended. */
    public static function spawn(Task $task): ?Task
    {
        $r = $task->recurrence;
        if (! $r) {
            return null;
        }
        $base = $task->due_at ?? $task->start_at ?? $task->completed_at ?? now();
        $nextDue = self::next(Carbon::parse($base), $r);
        // Never create an occurrence in the past (a task completed late jumps to the next one after today).
        for ($i = 0; $i < 400 && $nextDue->lt(today()); $i++) {
            $nextDue = self::next($nextDue, $r);
        }
        if (! empty($r['until']) && $nextDue->toDateString() > $r['until']) {
            $task->forceFill(['recurrence' => null])->saveQuietly();
            ActivityLogger::log('tasks.recurrence_ended', $task, 'انتهى التكرار ('.$r['until'].').');

            return null;
        }
        $shift = $task->due_at ? $task->due_at->diffInMinutes($nextDue, false) : null;

        return DB::transaction(function () use ($task, $r, $nextDue, $shift) {
            $copy = self::copy($task, null, $shift, $nextDue);
            $copy->forceFill(['recurrence' => $r, 'recurred_from_id' => $task->id])->saveQuietly();
            $task->forceFill(['recurrence' => null])->saveQuietly();
            ActivityLogger::log('tasks.recurred', $task, 'أُنشئت النسخة التالية '.$copy->code().' بتاريخ '.$nextDue->toDateString());

            return $copy;
        });
    }

    /** Scheduled rules: instances whose due date has arrived get their next instance. */
    public static function runScheduled(): int
    {
        $n = 0;
        Task::whereNotNull('recurrence')->whereNotNull('due_at')->where('due_at', '<=', now()->endOfDay())->chunkById(100, function ($rows) use (&$n) {
            foreach ($rows as $t) {
                if (($t->recurrence['on'] ?? 'complete') === 'schedule' && self::spawn($t)) {
                    $n++;
                }
            }
        });

        return $n;
    }

    /** Copy a task (and its subtasks) with dates moved by $shift minutes. */
    private static function copy(Task $src, ?Task $parent, ?int $shift, ?Carbon $due): Task
    {
        $list = $src->list;
        $first = $list->statuses()->first();
        $client = $src->client()->first();
        $copy = $src->replicate(['seq', 'completed_at', 'recurrence', 'recurred_from_id', 'description_text', 'created_at', 'updated_at', 'deleted_at']);
        $copy->forceFill([
            'seq' => TaskLists::nextSeq($client),
            'parent_id' => $parent?->id ?? $src->parent_id,
            'status_id' => $first->id,
            'completed_at' => null,
            'description_text' => $src->description_text,
            'start_at' => $src->start_at && $shift !== null ? $src->start_at->copy()->addMinutes($shift) : $src->start_at,
            'due_at' => $due ?? ($src->due_at && $shift !== null ? $src->due_at->copy()->addMinutes($shift) : $src->due_at),
            'position' => Tasks::nextPosition($src->task_list_id, $parent?->id ?? $src->parent_id),
        ]);
        if ($due && ! $src->due_has_time) {
            $copy->due_at = $due->copy()->startOfDay();
        }
        $copy->save();
        $copy->assignees()->sync($src->assignees()->pluck('users.id'));
        $copy->watchers()->sync($src->watchers()->pluck('users.id'));
        $copy->tags()->sync($src->tags()->pluck('tags.id'));
        foreach (CustomFieldValue::where('task_id', $src->id)->get() as $v) {
            CustomFieldValue::create(['task_id' => $copy->id, 'custom_field_id' => $v->custom_field_id, 'value' => $v->value, 'sort_value' => $v->sort_value]);
        }
        foreach ($src->checklists()->with('items')->get() as $cl) {
            /** @var Checklist $new */
            $new = $copy->checklists()->create(['name' => $cl->name, 'sort' => $cl->sort]);
            $map = [];
            $left = $cl->items;
            for ($pass = 0; $pass < 4 && $left->isNotEmpty(); $pass++) { // parents before their children
                $left = $left->reject(function ($item) use (&$map, $new) {
                    if ($item->parent_id && ! isset($map[$item->parent_id])) {
                        return false;
                    }
                    $map[$item->id] = $new->items()->create([
                        'text' => $item->text, 'assignee_id' => $item->assignee_id, 'sort' => $item->sort,
                        'parent_id' => $item->parent_id ? $map[$item->parent_id]->id : null,
                    ]);

                    return true;
                });
            }
        }
        foreach ($src->children()->get() as $child) {
            self::copy($child, $copy, $shift, null);
        }

        return $copy;
    }
}

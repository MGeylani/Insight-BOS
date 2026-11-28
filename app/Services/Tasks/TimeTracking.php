<?php

namespace App\Services\Tasks;

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Timer and manual time entries in one table. One running timer per person, on the server, so closing
 * the browser does not lose it. Each finished entry keeps the person's hourly cost at that moment.
 */
class TimeTracking
{
    public static function running(User $user): ?TimeEntry
    {
        return TimeEntry::where('user_id', $user->id)->running()->latest('id')->first();
    }

    /** Start a timer (stopping the running one first). */
    public static function start(User $user, ?Task $task, ?string $note = null): TimeEntry
    {
        Timesheets::assertOpen($user->id, now());

        return DB::transaction(function () use ($user, $task, $note) {
            if ($r = self::running($user)) {
                self::stop($r);
            }

            return TimeEntry::create([
                'user_id' => $user->id, 'task_id' => $task?->id, 'project_id' => $task?->project_id, 'client_id' => $task?->client_id,
                'started_at' => now(), 'billable' => self::billableDefault($task), 'note' => $note, 'source' => 'timer',
                'cost_rate' => $user->hourly_cost,
            ]);
        });
    }

    /** Stop a running timer. Less than a minute is discarded. */
    public static function stop(TimeEntry $e): ?TimeEntry
    {
        if ($e->ended_at) {
            return $e;
        }
        $minutes = (int) floor($e->started_at->diffInSeconds(now(), true) / 60);
        if ($minutes < 1) {
            $e->delete();

            return null;
        }
        $e->forceFill(['ended_at' => now(), 'minutes' => $minutes, 'cost_rate' => $e->user?->hourly_cost ?? $e->cost_rate])->save();
        if ($e->task) {
            ActivityLogger::log('tasks.time', $e->task, 'تسجيل وقت: '.self::label($minutes));
        }

        return $e;
    }

    /**
     * A manual entry: a date and either minutes or a from/to time.
     *
     * @param  array<string, mixed>  $data
     */
    public static function add(User $user, ?Task $task, array $data): TimeEntry
    {
        [$start, $end, $minutes] = self::period($data);
        Timesheets::assertOpen($user->id, $start);
        $e = TimeEntry::create([
            'user_id' => $user->id, 'task_id' => $task?->id, 'project_id' => $task?->project_id, 'client_id' => $task?->client_id,
            'started_at' => $start, 'ended_at' => $end, 'minutes' => $minutes,
            'billable' => array_key_exists('billable', $data) && $data['billable'] !== null ? (bool) $data['billable'] : self::billableDefault($task),
            'note' => $data['note'] ?? null, 'source' => 'manual', 'cost_rate' => $user->hourly_cost,
        ]);
        if ($task) {
            ActivityLogger::log('tasks.time', $task, 'تسجيل وقت يدوي: '.self::label($minutes));
        }

        return $e;
    }

    /** @param array<string, mixed> $data */
    public static function update(TimeEntry $e, array $data): TimeEntry
    {
        Timesheets::assertOpen($e->user_id, $e->started_at);
        if (isset($data['date'])) {
            Timesheets::assertOpen($e->user_id, $data['date']);
        }
        if ($e->ended_at === null && (isset($data['date']) || isset($data['minutes']))) {
            throw ValidationException::withMessages(['minutes' => 'أوقف المؤقت أولاً ثم عدّل المدة.']);
        }
        if (isset($data['date']) || isset($data['minutes']) || isset($data['from'])) {
            [$start, $end, $minutes] = self::period($data + ['date' => $e->started_at->toDateString()]);
            $e->forceFill(['started_at' => $start, 'ended_at' => $end, 'minutes' => $minutes]);
        }
        foreach (['billable', 'note'] as $f) {
            if (array_key_exists($f, $data)) {
                $e->$f = $f === 'billable' ? (bool) $data[$f] : $data[$f];
            }
        }
        $e->save();

        return $e;
    }

    /** Client work is billable by default; the agency's internal work is not. */
    public static function billableDefault(?Task $task): bool
    {
        return $task !== null && ! $task->client()->value('is_internal');
    }

    /**
     * Totals of finished entries (optionally of one project or task).
     *
     * @return array{minutes: int, billable_minutes: int, cost: float, uncosted_minutes: int}
     */
    public static function totals($query): array
    {
        $row = (clone $query)->finished()->selectRaw('
            coalesce(sum(minutes), 0) as minutes,
            coalesce(sum(case when billable then minutes else 0 end), 0) as billable_minutes,
            coalesce(sum(case when cost_rate is not null then minutes * cost_rate / 60.0 else 0 end), 0) as cost,
            coalesce(sum(case when cost_rate is null then minutes else 0 end), 0) as uncosted_minutes')->first();

        return [
            'minutes' => (int) $row->minutes, 'billable_minutes' => (int) $row->billable_minutes,
            'cost' => round((float) $row->cost, 2), 'uncosted_minutes' => (int) $row->uncosted_minutes,
        ];
    }

    public static function forProject(Project $p): array
    {
        return self::totals(TimeEntry::where('project_id', $p->id));
    }

    public static function label(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return trim(($h ? "{$h}س " : '').($m ? "{$m}د" : '')) ?: '0د';
    }

    /** @return array{0: Carbon, 1: Carbon, 2: int} */
    private static function period(array $data): array
    {
        $date = $data['date'] ?? today()->toDateString();
        if (! empty($data['from']) && ! empty($data['to'])) {
            $start = Carbon::parse("$date {$data['from']}");
            $end = Carbon::parse("$date {$data['to']}");
            if ($end->lte($start)) {
                throw ValidationException::withMessages(['to' => 'وقت النهاية بعد وقت البداية.']);
            }
            $minutes = (int) $start->diffInMinutes($end, true);
        } else {
            $minutes = (int) ($data['minutes'] ?? 0);
            $start = Carbon::parse("$date 09:00");
            $end = $start->copy()->addMinutes($minutes);
        }
        if ($minutes < 1 || $minutes > 24 * 60) {
            throw ValidationException::withMessages(['minutes' => 'المدة بين دقيقة و24 ساعة.']);
        }
        if ($start->isAfter(now()->endOfDay())) {
            throw ValidationException::withMessages(['date' => 'لا يُسجَّل وقت في المستقبل.']);
        }

        return [$start, $end, $minutes];
    }
}

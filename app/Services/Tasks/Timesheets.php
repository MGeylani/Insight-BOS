<?php

namespace App\Services\Tasks;

use App\Models\Task;
use App\Models\Timesheet;
use App\Models\TimeEntry;
use App\Models\User;
use App\Notifications\TaskNotification;
use App\Services\ActivityLogger;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Weekly timesheets (Saturday to Friday): submit, approve or reject; submitted and approved weeks are locked. */
class Timesheets
{
    public static function weekStart(Carbon|string $date): Carbon
    {
        return Carbon::parse($date)->startOfWeek(Carbon::SATURDAY)->startOfDay();
    }

    public static function for(int $userId, Carbon|string $date): Timesheet
    {
        $start = self::weekStart($date)->toDateString();

        return Timesheet::where('user_id', $userId)->whereDate('week_start', $start)->first()
            ?? new Timesheet(['user_id' => $userId, 'week_start' => $start, 'status' => 'draft']);
    }

    /** Refuse changes to time in a submitted or approved week. */
    public static function assertOpen(int $userId, Carbon|string $date): void
    {
        $ts = Timesheet::where('user_id', $userId)->whereDate('week_start', self::weekStart($date)->toDateString())->first();
        if ($ts && $ts->isLocked()) {
            throw ValidationException::withMessages(['date' => 'أسبوع هذا القيد '.($ts->status === 'approved' ? 'معتمد' : 'مُرسل للاعتماد').'؛ اطلب إعادة فتحه لتعديله.']);
        }
    }

    public static function submit(User $user, Carbon|string $date, ?string $note = null): Timesheet
    {
        $ts = self::for($user->id, $date);
        if ($ts->isLocked()) {
            throw ValidationException::withMessages(['week' => 'الأسبوع مُرسل أو معتمد أصلاً.']);
        }
        $start = self::weekStart($date);
        if (TimeEntry::where('user_id', $user->id)->running()->whereBetween('started_at', [$start, $start->copy()->addDays(6)->endOfDay()])->exists()) {
            throw ValidationException::withMessages(['week' => 'أوقف المؤقت الجاري قبل إرسال الأسبوع.']);
        }
        $ts->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'note' => $note, 'decided_by' => null, 'decided_at' => null])->save();
        ActivityLogger::log('timesheets.submitted', $ts, "{$user->name} أرسل ساعات أسبوع {$ts->week_start->toDateString()} للاعتماد");
        foreach (User::where('is_active', true)->get() as $approver) {
            if ($approver->id !== $user->id && self::canApprove($approver, $user)) {
                $approver->notify(new TaskNotification(['kind' => 'timesheet.submitted', 'title' => "{$user->name} أرسل جدول ساعات أسبوع {$ts->week_start->toDateString()}", 'url' => '/tasks/timesheets']));
            }
        }

        return $ts;
    }

    public static function decide(Timesheet $ts, User $approver, string $decision, ?string $note = null): Timesheet
    {
        if ($decision === 'reopen') {
            $ts->forceFill(['status' => 'draft', 'decided_by' => $approver->id, 'decided_at' => now(), 'note' => $note ?? $ts->note])->save();
        } else {
            if ($ts->status !== 'submitted') {
                throw ValidationException::withMessages(['status' => 'يُعتمد أو يُرفض الأسبوع المُرسل فقط.']);
            }
            $ts->forceFill(['status' => $decision === 'approve' ? 'approved' : 'rejected', 'decided_by' => $approver->id, 'decided_at' => now(), 'note' => $note])->save();
        }
        $label = ['approve' => 'اعتُمد', 'reject' => 'رُفض', 'reopen' => 'أعيد فتح'][$decision];
        ActivityLogger::log("timesheets.$decision", $ts, "$label جدول ساعات {$ts->user?->name} لأسبوع {$ts->week_start->toDateString()}");
        $ts->user?->notify(new TaskNotification([
            'kind' => 'timesheet.'.$decision, 'title' => "$label جدول ساعاتك لأسبوع {$ts->week_start->toDateString()}".($note ? ": $note" : ''),
            'url' => '/my-work/time?week='.$ts->week_start->toDateString(),
        ]));

        return $ts;
    }

    /**
     * all: anyone except yourself; assigned: people on projects you manage; never your own week.
     */
    public static function canApprove(User $approver, User $owner): bool
    {
        if ($approver->id === $owner->id) {
            return false;
        }

        return match ($approver->permissionScope('tasks.approve_time')) {
            'all' => true,
            'assigned' => \App\Models\Project::where('manager_id', $approver->id)
                ->where(fn ($q) => $q->whereIn('id', fn ($w) => $w->select('project_id')->from('project_members')->where('user_id', $owner->id))
                    ->orWhereIn('id', TimeEntry::where('user_id', $owner->id)->whereNotNull('project_id')->select('project_id')))->exists(),
            default => false,
        };
    }

    /** @return array<string, mixed> */
    public static function payload(Timesheet $ts): array
    {
        return [
            'id' => $ts->id, 'status' => $ts->status, 'status_label' => Timesheet::STATUSES[$ts->status] ?? $ts->status,
            'submitted_at' => $ts->submitted_at?->toIso8601String(), 'decided_by' => $ts->decider?->name,
            'decided_at' => $ts->decided_at?->toIso8601String(), 'note' => $ts->note, 'locked' => $ts->isLocked(),
        ];
    }
}

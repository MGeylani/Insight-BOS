<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sends due CRM activity reminders to the responsible person (in-app notification).
// The server needs a cron entry: * * * * * php artisan schedule:run
Artisan::command('crm:reminders', function () {
    $sent = 0;
    \App\Models\CrmActivity::dueReminders()->with('user')->chunkById(100, function ($rows) use (&$sent) {
        foreach ($rows as $activity) {
            $recipient = $activity->user ?? $activity->creator;
            if ($recipient && $recipient->is_active) {
                $recipient->notify(new \App\Notifications\ActivityReminder($activity));
                $sent++;
            }
            $activity->forceFill(['reminded_at' => now()])->saveQuietly();
        }
    });
    $this->info("Sent $sent reminder(s).");
})->purpose('Send due CRM activity reminders');

\Illuminate\Support\Facades\Schedule::command('crm:reminders')->everyFiveMinutes()->withoutOverlapping();

// Retries attachments whose upload to the storage provider failed (temporary copy kept until it succeeds).
Artisan::command('storage:retry', function () {
    [$ok, $bad] = \App\Services\Storage\Attachments::retryFailed();
    $this->info("Uploaded $ok, still failing $bad.");
})->purpose('Retry failed attachment uploads');

\Illuminate\Support\Facades\Schedule::command('storage:retry')->everyTenMinutes()->withoutOverlapping();

// Personal task reminders ("remind me at …") become in-app notifications.
Artisan::command('tasks:reminders', function () {
    $sent = 0;
    \App\Models\Reminder::due()->with(['user', 'task'])->chunkById(100, function ($rows) use (&$sent) {
        foreach ($rows as $r) {
            if ($r->user && $r->user->is_active) {
                $task = $r->task && ! $r->task->trashed() ? $r->task : null;
                $r->user->notify(new \App\Notifications\TaskNotification([
                    'kind' => 'task.reminder',
                    'title' => 'تذكير: '.($r->note ?: ($task ? $task->code().' · '.$task->title : 'تذكير')),
                    'body' => $task && $r->note ? $task->code().' · '.$task->title : null,
                    'task_id' => $task?->id,
                    'url' => $task ? "/tasks/{$task->id}" : '/my-work',
                ]));
                $sent++;
            }
            $r->forceFill(['sent_at' => now()])->saveQuietly();
        }
    });
    $this->info("Sent $sent reminder(s).");
})->purpose('Send due task reminders');

\Illuminate\Support\Facades\Schedule::command('tasks:reminders')->everyMinute()->withoutOverlapping();

// Morning summary: each person's tasks due today and overdue (one notification a day, only when there is something).
Artisan::command('tasks:digest', function () {
    $sent = 0;
    foreach (\App\Models\User::where('is_active', true)->get() as $user) {
        if (! $user->hasPermission('tasks.view')) {
            continue;
        }
        $mine = fn () => \App\Models\Task::query()->open()->whereIn('tasks.id', fn ($q) => $q->select('task_id')->from('task_assignees')->where('user_id', $user->id));
        $today = $mine()->whereDate('tasks.due_at', today())->count();
        $late = $mine()->overdue()->count();
        $comments = \App\Models\Comment::where('assigned_to', $user->id)->whereNull('resolved_at')->count();
        if (! $today && ! $late && ! $comments) {
            continue;
        }
        $already = $user->notifications()->where('created_at', '>=', today())->where('data->kind', 'task.digest')->exists();
        if ($already) {
            continue;
        }
        $parts = array_filter([
            $today ? "$today مهمة اليوم" : null, $late ? "$late متأخرة" : null, $comments ? "$comments تعليق مسند" : null,
        ]);
        $user->notify(new \App\Notifications\TaskNotification(['kind' => 'task.digest', 'title' => 'صباح الخير: '.implode('، ', $parts), 'url' => '/my-work']));
        $sent++;
    }
    $this->info("Sent $sent digest(s).");
})->purpose('Morning summary of due and overdue tasks');

\Illuminate\Support\Facades\Schedule::command('tasks:digest')->dailyAt('08:30')->withoutOverlapping();

// Recurring tasks "by schedule": the next instance appears on the current one's due date, done or not.
Artisan::command('tasks:recurring', function () {
    $this->info('Created '.\App\Services\Tasks\Recurrence::runScheduled().' recurring task(s).');
})->purpose('Create the next instance of scheduled recurring tasks');

\Illuminate\Support\Facades\Schedule::command('tasks:recurring')->dailyAt('00:10')->withoutOverlapping();

// Time-based automations: due soon, overdue, status unchanged for N days, first day of the month.
Artisan::command('tasks:automations', function () {
    $this->info('Ran '.\App\Services\Tasks\Automations::tick().' automation(s).');
})->purpose('Run time-based task automations');

\Illuminate\Support\Facades\Schedule::command('tasks:automations')->everyFifteenMinutes()->withoutOverlapping();

// Finance: draft invoices for retainer instalments coming due (owner notified to review and issue).
Artisan::command('finance:retainer-invoices', function () {
    $this->info('Drafted '.\App\Services\Finance\Invoices::draftRetainers().' invoice(s).');
})->purpose('Draft invoices for retainer instalments due soon');

\Illuminate\Support\Facades\Schedule::command('finance:retainer-invoices')->dailyAt('07:00')->withoutOverlapping();

// Finance: remind invoice owners of overdue invoices (1, 7, 14, 30, 60 days late).
Artisan::command('finance:overdue', function () {
    $this->info('Reminded '.\App\Services\Finance\Receivables::remindOverdue().' invoice(s).');
})->purpose('Remind owners of overdue invoices');

\Illuminate\Support\Facades\Schedule::command('finance:overdue')->dailyAt('09:10')->withoutOverlapping();

// Finance: budget alerts for running budgets (labor lines and anything missed), daily.
Artisan::command('finance:budgets', function () {
    $this->info('Sent '.\App\Services\Finance\Budgets::checkAll().' budget alert(s).');
})->purpose('Warn budget owners about lines near or over plan');

\Illuminate\Support\Facades\Schedule::command('finance:budgets')->dailyAt('07:40')->withoutOverlapping();

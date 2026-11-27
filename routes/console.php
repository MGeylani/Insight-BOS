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

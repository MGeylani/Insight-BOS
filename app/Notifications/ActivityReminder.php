<?php

namespace App\Notifications;

use App\Models\CrmActivity;
use Illuminate\Notifications\Notification;

class ActivityReminder extends Notification
{
    public function __construct(public CrmActivity $activity) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $a = $this->activity;
        $url = match ($a->subject_type) {
            'lead' => "/crm/leads/{$a->subject_id}",
            'client' => "/crm/clients/{$a->subject_id}",
            'opportunity' => "/crm/opportunities/{$a->subject_id}",
            default => '/crm/activities',
        };

        return [
            'kind' => 'crm.reminder',
            'title' => 'تذكير: '.$a->title,
            'type' => $a->type,
            'due_at' => $a->due_at?->toIso8601String(),
            'activity_id' => $a->id,
            'url' => $url,
        ];
    }
}

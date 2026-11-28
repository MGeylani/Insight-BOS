<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * In-app notification about a task (database channel; no queue on the server).
 * kind: task.assigned | task.mentioned | task.comment | task.comment_assigned | task.status | task.due | task.reminder
 */
class TaskNotification extends Notification
{
    /** @param array<string, mixed> $data */
    public function __construct(public array $data) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return $this->data;
    }
}

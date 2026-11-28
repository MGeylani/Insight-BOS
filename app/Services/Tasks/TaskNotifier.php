<?php

namespace App\Services\Tasks;

use App\Models\Comment;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskNotification;
use App\Support\RichText;
use Illuminate\Support\Collection;

/**
 * Who hears about what. The person who made the change is never notified, nor anyone who cannot
 * see the task. One notification per person per event (the most specific reason wins).
 */
class TaskNotifier
{
    /** @param iterable<int>|Collection<int, int> $userIds */
    public static function assigned(Task $task, iterable $userIds): void
    {
        self::send($task, $userIds, 'task.assigned', self::actor().' أسند إليك المهمة '.$task->code().': '.$task->title);
    }

    /** People newly @mentioned in the description. */
    public static function descriptionMentions(Task $task, ?array $before, ?array $after): void
    {
        $new = array_diff(RichText::mentions($after), RichText::mentions($before));
        self::send($task, $new, 'task.mentioned', self::actor().' أشار إليك في وصف المهمة '.$task->code().': '.$task->title, ['body' => RichText::excerpt($after)]);
    }

    public static function statusChanged(Task $task, string $statusName): void
    {
        self::send($task, self::followers($task), 'task.status', self::actor()." غيّر حالة {$task->code()} إلى «{$statusName}»: {$task->title}");
    }

    public static function dueChanged(Task $task): void
    {
        $when = $task->due_at ? $task->due_at->format($task->due_has_time ? 'Y-m-d H:i' : 'Y-m-d') : 'بلا تاريخ';
        self::send($task, self::followers($task), 'task.due', self::actor()." غيّر موعد تسليم {$task->code()} إلى $when: {$task->title}");
    }

    /**
     * A new comment: the assignee of the comment, then the people mentioned, then the task's watchers.
     *
     * @param  list<int>  $mentioned
     */
    public static function comment(Task $task, Comment $c, array $mentioned): void
    {
        $extra = ['body' => RichText::excerpt($c->body), 'comment_id' => $c->id, 'url' => "/tasks/{$task->id}#comment-{$c->id}"];
        // Guests never hear about internal comments: count them as already told.
        $done = $c->is_internal ? User::where('is_guest', true)->pluck('id')->all() : [];
        if ($c->assigned_to && ! in_array((int) $c->assigned_to, $done, true)) {
            $done = array_merge($done, self::send($task, [$c->assigned_to], 'task.comment_assigned', self::actor().' أسند إليك تعليقاً في '.$task->code().': '.$task->title, $extra));
        }
        $done = array_merge($done, self::send($task, array_diff($mentioned, $done), 'task.mentioned', self::actor().' أشار إليك في تعليق على '.$task->code().': '.$task->title, $extra));
        // Repliers also hear about replies in their thread.
        $thread = $c->parent_id ? Comment::where('id', $c->parent_id)->orWhere('parent_id', $c->parent_id)->pluck('user_id')->all() : [];
        self::send($task, array_diff(array_unique(array_merge(self::followers($task)->all(), $thread)), $done), 'task.comment',
            self::actor().' علّق على '.$task->code().': '.$task->title, $extra);
    }

    public static function commentAssigned(Task $task, Comment $c): void
    {
        self::send($task, [$c->assigned_to], 'task.comment_assigned', self::actor().' أسند إليك تعليقاً في '.$task->code().': '.$task->title,
            ['body' => RichText::excerpt($c->body), 'comment_id' => $c->id, 'url' => "/tasks/{$task->id}#comment-{$c->id}"]);
    }

    /** Watchers and assignees. */
    public static function followers(Task $task): Collection
    {
        return $task->watchers()->pluck('users.id')->merge($task->assignees()->pluck('users.id'))->unique()->values();
    }

    /**
     * @param  iterable<int>  $userIds
     * @param  array<string, mixed>  $extra
     * @return list<int> ids actually notified
     */
    public static function send(Task $task, iterable $userIds, string $kind, string $title, array $extra = []): array
    {
        $ids = collect($userIds)->filter()->map(fn ($id) => (int) $id)->unique()->reject(fn ($id) => $id === auth()->id())->values();
        if ($ids->isEmpty()) {
            return [];
        }
        $sent = [];
        foreach (User::whereIn('id', $ids)->where('is_active', true)->get() as $user) {
            if (! $task->isVisibleTo($user)) {
                continue;
            }
            $user->notify(new TaskNotification([
                'kind' => $kind,
                'title' => $title,
                'task_id' => $task->id,
                'task_code' => $task->code(),
                'actor' => auth()->user()?->name,
                'url' => "/tasks/{$task->id}",
            ] + $extra));
            $sent[] = $user->id;
        }

        return $sent;
    }

    private static function actor(): string
    {
        return auth()->user()?->name ?? 'النظام';
    }
}

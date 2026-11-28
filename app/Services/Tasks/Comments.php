<?php

namespace App\Services\Tasks;

use App\Models\Comment;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\RichText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Threaded comments on tasks: replies are one level deep, a comment can be assigned and resolved. */
class Comments
{
    public static function add(Task $task, User $user, mixed $body, ?int $parentId = null, ?int $assignTo = null): Comment
    {
        $doc = RichText::normalize($body);
        if (! $doc) {
            throw ValidationException::withMessages(['body' => 'اكتب التعليق أولاً.']);
        }
        $parent = null;
        if ($parentId) {
            $parent = Comment::where('commentable_type', 'task')->where('commentable_id', $task->id)->find($parentId);
            if (! $parent) {
                throw ValidationException::withMessages(['parent_id' => 'التعليق الأصلي غير موجود.']);
            }
            $parent = $parent->parent_id ? $parent->parent : $parent; // replies attach to the thread's first comment
        }
        if ($assignTo && ! User::whereKey($assignTo)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['assigned_to' => 'المستخدم غير موجود.']);
        }

        return DB::transaction(function () use ($task, $user, $doc, $parent, $assignTo) {
            $c = Comment::create([
                'commentable_type' => 'task', 'commentable_id' => $task->id, 'parent_id' => $parent?->id,
                'user_id' => $user->id, 'body' => $doc, 'body_text' => RichText::toPlain($doc), 'assigned_to' => $assignTo,
            ]);
            // Commenting means following.
            $task->watchers()->syncWithoutDetaching([$user->id]);
            ActivityLogger::log('tasks.commented', $task, ($assignTo ? 'تعليق مُسند: ' : 'تعليق: ').RichText::excerpt($doc, 80), ['comment_id' => $c->id]);
            TaskNotifier::comment($task, $c, RichText::mentions($doc));

            return $c;
        });
    }

    public static function edit(Comment $c, mixed $body): Comment
    {
        $doc = RichText::normalize($body);
        if (! $doc) {
            throw ValidationException::withMessages(['body' => 'التعليق لا يكون فارغاً؛ احذفه بدلاً من ذلك.']);
        }
        $before = RichText::mentions($c->body);
        $c->forceFill(['body' => $doc, 'body_text' => RichText::toPlain($doc), 'edited_at' => now()])->save();
        $new = array_values(array_diff(RichText::mentions($doc), $before));
        if ($new && ($task = $c->commentable)) {
            TaskNotifier::send($task, $new, 'task.mentioned', (auth()->user()?->name).' أشار إليك في تعليق على '.$task->code().': '.$task->title,
                ['body' => RichText::excerpt($doc), 'comment_id' => $c->id, 'url' => "/tasks/{$task->id}#comment-{$c->id}"]);
        }

        return $c;
    }

    public static function delete(Comment $c): void
    {
        DB::transaction(function () use ($c) {
            Comment::where('parent_id', $c->id)->update(['deleted_at' => now()]);
            $c->delete();
        });
    }

    public static function assign(Comment $c, ?int $userId): Comment
    {
        $c->forceFill(['assigned_to' => $userId, 'resolved_at' => null, 'resolved_by' => null])->save();
        if ($userId && ($task = $c->commentable)) {
            TaskNotifier::commentAssigned($task, $c);
        }

        return $c;
    }

    public static function resolve(Comment $c, bool $resolved, int $userId): Comment
    {
        $c->forceFill($resolved ? ['resolved_at' => now(), 'resolved_by' => $userId] : ['resolved_at' => null, 'resolved_by' => null])->save();
        if ($c->assigned_to && ($task = $c->commentable)) {
            ActivityLogger::log('tasks.comment_resolved', $task, ($resolved ? 'تم حلّ تعليق مُسند: ' : 'أعيد فتح تعليق مُسند: ').RichText::excerpt($c->body, 80));
        }

        return $c;
    }

    /** Thread payload for the task page. @return list<array<string, mixed>> */
    public static function payload(Task $task, User $user): array
    {
        $canModerate = $task->isVisibleTo($user, 'tasks.delete');
        $canEditTask = $task->isVisibleTo($user, 'tasks.edit');
        $all = Comment::with(['user:id,name', 'assignee:id,name', 'resolver:id,name'])
            ->where('commentable_type', 'task')->where('commentable_id', $task->id)->oldest('id')->get();
        $row = fn (Comment $c) => [
            'id' => $c->id, 'parent_id' => $c->parent_id, 'body' => $c->body,
            'user' => $c->user?->only('id', 'name'),
            'created_at' => $c->created_at?->toIso8601String(), 'edited_at' => $c->edited_at?->toIso8601String(),
            'assignee' => $c->assignee?->only('id', 'name'),
            'resolved_at' => $c->resolved_at?->toIso8601String(), 'resolver' => $c->resolver?->name,
            'can_edit' => (int) $c->user_id === $user->id,
            'can_delete' => (int) $c->user_id === $user->id || $canModerate,
            'can_resolve' => (int) $c->assigned_to === $user->id || (int) $c->user_id === $user->id || $canEditTask,
        ];

        return $all->whereNull('parent_id')->map(fn (Comment $c) => [
            ...$row($c),
            'replies' => $all->where('parent_id', $c->id)->map($row)->values()->all(),
        ])->values()->all();
    }
}

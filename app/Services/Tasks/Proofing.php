<?php

namespace App\Services\Tasks;

use App\Models\Attachment;
use App\Models\ProofComment;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Storage\Attachments;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Reviewing images and videos attached to a task: comments pinned to a point of the image (x, y in % of
 * its size) or to a moment of the video, replies, resolving, and new versions of the file. The comment
 * author is a user now; `author_name` is kept for client comments from the client portal later.
 */
class Proofing
{
    /** The task that owns a proofable attachment, or 404. */
    public static function taskOf(Attachment $a): Task
    {
        abort_unless($a->attachable_type === 'task' && $a->isProofable(), 404);

        return Task::findOrFail($a->attachable_id);
    }

    /** All versions of the file, oldest first (following previous_id both ways). @return Collection<int, Attachment> */
    public static function versions(Attachment $a): Collection
    {
        $first = $a;
        for ($i = 0; $i < 50 && $first->previous_id && ($p = Attachment::find($first->previous_id)); $i++) {
            $first = $p;
        }
        $chain = collect([$first]);
        for ($i = 0; $i < 50 && ($next = Attachment::where('previous_id', $chain->last()->id)->oldest('id')->first()); $i++) {
            $chain->push($next);
        }

        return $chain;
    }

    public static function addComment(Attachment $a, User $user, array $data): ProofComment
    {
        $task = self::taskOf($a);
        $body = trim((string) ($data['body'] ?? ''));
        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'اكتب التعليق.']);
        }
        $parent = ! empty($data['parent_id']) ? ProofComment::where('attachment_id', $a->id)->whereNull('parent_id')->find($data['parent_id']) : null;
        if (! empty($data['parent_id']) && ! $parent) {
            throw ValidationException::withMessages(['parent_id' => 'التعليق الأصلي غير موجود.']);
        }
        $pin = fn ($v) => $v === null || $v === '' || $parent ? null : max(0, min(100, round((float) $v, 3)));
        $c = ProofComment::create([
            'attachment_id' => $a->id, 'task_id' => $task->id, 'parent_id' => $parent?->id, 'user_id' => $user->id,
            'body' => mb_substr($body, 0, 4000),
            'x' => $pin($data['x'] ?? null), 'y' => $pin($data['y'] ?? null),
            'at_seconds' => $parent || ! is_numeric($data['at_seconds'] ?? null) ? null : max(0, round((float) $data['at_seconds'], 2)),
        ]);
        $where = $c->at_seconds !== null ? ' عند '.self::clock($c->at_seconds) : '';
        ActivityLogger::log('tasks.proof_comment', $task, "تعليق على «{$a->name}» (ن{$a->version}){$where}: ".mb_substr($body, 0, 80), ['attachment_id' => $a->id]);
        $notify = TaskNotifier::followers($task)->merge($parent ? [$parent->user_id] : [])->all();
        TaskNotifier::send($task, $notify, 'task.proof', "{$user->name} علّق على «{$a->name}» في {$task->code()}",
            ['body' => mb_substr($body, 0, 140), 'url' => "/tasks/{$task->id}/proof/{$a->id}#c{$c->id}"]);
        Automations::fire('comment_added', $task, ['proof_comment_id' => $c->id]);

        return $c;
    }

    public static function resolve(ProofComment $c, User $user, bool $resolved): void
    {
        $c->forceFill(['resolved_at' => $resolved ? now() : null, 'resolved_by' => $resolved ? $user->id : null])->save();
    }

    /** Upload a new version of the file (it keeps the comments of each version apart). */
    public static function newVersion(Attachment $prev, UploadedFile $file, User $user): Attachment
    {
        $task = self::taskOf($prev);
        $latest = self::versions($prev)->last();
        if ($latest->id !== $prev->id) {
            throw ValidationException::withMessages(['file' => 'ارفع النسخة الجديدة على آخر نسخة (ن'.$latest->version.').']);
        }
        $a = Attachments::store($task, $file, $user);
        $a->forceFill(['previous_id' => $prev->id, 'version' => $prev->version + 1])->save();
        ActivityLogger::log('tasks.proof_version', $task, "نسخة جديدة ن{$a->version} من «{$prev->name}»");
        TaskNotifier::send($task, TaskNotifier::followers($task), 'task.proof', "نسخة جديدة ن{$a->version} من «{$prev->name}» في {$task->code()}",
            ['url' => "/tasks/{$task->id}/proof/{$a->id}"]);

        return $a;
    }

    /** @return array<string, mixed> */
    public static function payload(Attachment $a, User $user): array
    {
        $task = self::taskOf($a);
        $versions = self::versions($a);
        $counts = ProofComment::whereIn('attachment_id', $versions->pluck('id'))->whereNull('parent_id')->whereNull('resolved_at')
            ->selectRaw('attachment_id, count(*) as n')->groupBy('attachment_id')->pluck('n', 'attachment_id');
        $comments = ProofComment::with(['user:id,name', 'replies.user:id,name'])->where('attachment_id', $a->id)->whereNull('parent_id')->orderBy('id')->get();
        $row = fn (ProofComment $c) => [
            ...$c->only('id', 'body', 'x', 'y', 'at_seconds'),
            'author' => $c->authorName(), 'user_id' => $c->user_id, 'created_at' => $c->created_at?->toIso8601String(),
            'resolved' => $c->resolved_at !== null,
            'can_edit' => (int) $c->user_id === $user->id,
        ];

        return [
            'task' => ['id' => $task->id, 'code' => $task->code(), 'title' => $task->title],
            'file' => [...$a->only('id', 'name', 'mime', 'version', 'status'), 'kind' => str_starts_with($a->mime, 'video/') ? 'video' : 'image', 'src' => "/attachments/{$a->id}/inline"],
            'versions' => $versions->map(fn (Attachment $v) => [...$v->only('id', 'version', 'name', 'status'), 'open' => (int) ($counts[$v->id] ?? 0),
                'created_at' => $v->created_at?->toIso8601String(), 'deleted' => $v->trashed()])->values(),
            'comments' => $comments->map(fn (ProofComment $c) => [...$row($c), 'replies' => $c->replies->map($row)->values()])->values(),
            'can' => ['comment' => $task->isVisibleTo($user), 'upload' => Attachments::can($user, $task, 'edit')],
        ];
    }

    public static function clock(float $s): string
    {
        return sprintf('%d:%02d', intdiv((int) $s, 60), (int) $s % 60);
    }
}

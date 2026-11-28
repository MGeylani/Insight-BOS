<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\ProofComment;
use App\Models\Task;
use App\Services\Storage\Attachments;
use App\Services\Tasks\Proofing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Reviewing a task's images and videos: pinned comments, replies, resolving and new versions. */
class ProofController extends Controller
{
    public function show(Request $request, Task $task, Attachment $attachment): Response
    {
        $this->authorizeRecord($task, 'tasks.view');
        abort_unless($attachment->attachable_type === 'task' && (int) $attachment->attachable_id === $task->id, 404);

        return Inertia::render('tasks/Proof', Proofing::payload($attachment, $request->user()));
    }

    public function comment(Request $request, Attachment $attachment): RedirectResponse
    {
        $this->authorizeRecord(Proofing::taskOf($attachment), 'tasks.view');
        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'], 'parent_id' => ['nullable', 'integer'],
            'x' => ['nullable', 'numeric'], 'y' => ['nullable', 'numeric'], 'at_seconds' => ['nullable', 'numeric'],
        ]);
        Proofing::addComment($attachment, $request->user(), $data);

        return back();
    }

    public function update(Request $request, ProofComment $comment): RedirectResponse
    {
        $task = $comment->task()->firstOrFail();
        $this->authorizeRecord($task, 'tasks.view');
        if ($request->has('resolved')) {
            Proofing::resolve($comment, $request->user(), $request->boolean('resolved'));

            return back();
        }
        abort_unless((int) $comment->user_id === $request->user()->id, 403);
        $comment->update($request->validate(['body' => ['required', 'string', 'max:4000']]));

        return back();
    }

    public function destroy(Request $request, ProofComment $comment): RedirectResponse
    {
        $task = $comment->task()->firstOrFail();
        abort_unless((int) $comment->user_id === $request->user()->id || $task->isVisibleTo($request->user(), 'tasks.edit'), 403);
        $comment->delete();

        return back();
    }

    public function version(Request $request, Attachment $attachment): RedirectResponse
    {
        $task = Proofing::taskOf($attachment);
        abort_unless(Attachments::can($request->user(), $task, 'edit'), 403);
        $request->validate(['file' => ['required', 'file']], ['file.file' => 'تعذّر استلام الملف؛ ربما تجاوز حد الرفع في الخادم.']);
        $new = Proofing::newVersion($attachment, $request->file('file'), $request->user());

        return redirect("/tasks/{$task->id}/proof/{$new->id}")->with('success', "رُفعت النسخة ن{$new->version}.");
    }
}

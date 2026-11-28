<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Task;
use App\Services\Tasks\Comments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Anyone who can see a task can comment on it; authors edit their own comments. */
class CommentController extends Controller
{
    public function store(Request $request, Task $task): RedirectResponse
    {
        $this->authorizeRecord($task, 'tasks.view');
        $data = $request->validate([
            'body' => ['required'],
            'parent_id' => ['nullable', 'integer'],
            'assigned_to' => ['nullable', 'integer'],
        ]);
        Comments::add($task, $request->user(), $data['body'], $data['parent_id'] ?? null, $data['assigned_to'] ?? null);

        return back();
    }

    public function update(Request $request, Comment $comment): RedirectResponse
    {
        abort_unless((int) $comment->user_id === $request->user()->id, 403);
        $this->authorizeRecord($this->task($comment), 'tasks.view');
        Comments::edit($comment, $request->validate(['body' => ['required']])['body']);

        return back();
    }

    public function destroy(Request $request, Comment $comment): RedirectResponse
    {
        $task = $this->task($comment);
        abort_unless((int) $comment->user_id === $request->user()->id || $task->isVisibleTo($request->user(), 'tasks.delete'), 403);
        Comments::delete($comment);

        return back();
    }

    public function resolve(Request $request, Comment $comment): RedirectResponse
    {
        $task = $this->task($comment);
        $user = $request->user();
        abort_unless((int) $comment->assigned_to === $user->id || (int) $comment->user_id === $user->id || $task->isVisibleTo($user, 'tasks.edit'), 403);
        Comments::resolve($comment, $request->boolean('resolved', true), $user->id);

        return back();
    }

    public function assign(Request $request, Comment $comment): RedirectResponse
    {
        $task = $this->task($comment);
        $user = $request->user();
        abort_unless((int) $comment->user_id === $user->id || $task->isVisibleTo($user, 'tasks.edit'), 403);
        $data = $request->validate(['assigned_to' => ['nullable', 'integer', 'exists:users,id']]);
        Comments::assign($comment, $data['assigned_to'] ?? null);

        return back();
    }

    private function task(Comment $comment): Task
    {
        $task = $comment->commentable;
        abort_unless($task instanceof Task && ! $task->trashed(), 404);
        $this->authorizeRecord($task, 'tasks.view');

        return $task;
    }
}

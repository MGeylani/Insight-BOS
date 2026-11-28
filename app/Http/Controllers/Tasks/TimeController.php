<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Tasks\TimeTracking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Timer and time entries. People manage their own entries; "all" scope on tasks.track_time manages anyone's. */
class TimeController extends Controller
{
    public function start(Request $request): RedirectResponse
    {
        $data = $request->validate(['task_id' => ['nullable', 'integer', 'exists:tasks,id'], 'note' => ['nullable', 'string', 'max:500']]);
        $task = $this->task($request, $data['task_id'] ?? null);
        TimeTracking::start($request->user(), $task, $data['note'] ?? null);

        return back()->with('success', $task ? "بدأ المؤقت على {$task->code()}." : 'بدأ المؤقت.');
    }

    public function stop(Request $request): RedirectResponse
    {
        $running = TimeTracking::running($request->user());
        $e = $running ? TimeTracking::stop($running) : null;

        return back()->with('success', $e ? 'أوقف المؤقت: '.TimeTracking::label($e->minutes).'.' : 'أوقف المؤقت (أقل من دقيقة، لم يُسجَّل).');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'task_id' => ['nullable', 'integer', 'exists:tasks,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'date' => ['required', 'date'],
            'minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'from' => ['nullable', 'date_format:H:i'],
            'to' => ['nullable', 'date_format:H:i'],
            'billable' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $user = $request->user();
        $for = $user;
        if (! empty($data['user_id']) && (int) $data['user_id'] !== $user->id) {
            abort_unless($user->permissionScope('tasks.track_time') === 'all', 403);
            $for = User::findOrFail($data['user_id']);
        }
        TimeTracking::add($for, $this->task($request, $data['task_id'] ?? null), $data);

        return back()->with('success', 'تم تسجيل الوقت.');
    }

    public function update(Request $request, TimeEntry $entry): RedirectResponse
    {
        $this->authorizeEntry($request, $entry);
        $data = $request->validate([
            'date' => ['sometimes', 'date'],
            'minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'from' => ['sometimes', 'nullable', 'date_format:H:i'],
            'to' => ['sometimes', 'nullable', 'date_format:H:i'],
            'billable' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        TimeTracking::update($entry, $data);

        return back()->with('success', 'تم تعديل القيد.');
    }

    public function destroy(Request $request, TimeEntry $entry): RedirectResponse
    {
        $this->authorizeEntry($request, $entry);
        \App\Services\Tasks\Timesheets::assertOpen($entry->user_id, $entry->started_at);
        $entry->delete();

        return back()->with('success', 'حُذف القيد.');
    }

    private function task(Request $request, ?int $id): ?Task
    {
        if (! $id) {
            return null;
        }
        $task = Task::findOrFail($id);
        $this->authorizeRecord($task, 'tasks.view');

        return $task;
    }

    private function authorizeEntry(Request $request, TimeEntry $entry): void
    {
        $user = $request->user();
        abort_unless((int) $entry->user_id === $user->id || $user->permissionScope('tasks.track_time') === 'all', 403);
    }
}

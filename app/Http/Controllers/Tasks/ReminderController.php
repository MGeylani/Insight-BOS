<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Reminder;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Personal reminders ("remind me"), optionally about a task. */
class ReminderController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'task_id' => ['nullable', 'integer', 'exists:tasks,id'],
            'remind_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        if (! empty($data['task_id'])) {
            $this->authorizeRecord(Task::findOrFail($data['task_id']), 'tasks.view');
        }
        $at = Carbon::parse($data['remind_at']);
        if ($at->lt(now()->subMinute())) {
            return back()->withErrors(['remind_at' => 'اختر وقتاً في المستقبل.']);
        }
        Reminder::create(['user_id' => $request->user()->id, 'task_id' => $data['task_id'] ?? null, 'remind_at' => $at, 'note' => $data['note'] ?? null]);

        return back()->with('success', 'سيصلك التذكير في '.$at->format('Y-m-d H:i').'.');
    }

    /** Mark done / not done, or move it. */
    public function update(Request $request, Reminder $reminder): RedirectResponse
    {
        abort_unless((int) $reminder->user_id === $request->user()->id, 403);
        $data = $request->validate(['done' => ['sometimes', 'boolean'], 'remind_at' => ['sometimes', 'date']]);
        if (array_key_exists('done', $data)) {
            $reminder->done_at = $data['done'] ? now() : null;
        }
        if (isset($data['remind_at'])) {
            $reminder->remind_at = Carbon::parse($data['remind_at']);
            $reminder->sent_at = null;
        }
        $reminder->save();

        return back();
    }

    public function destroy(Request $request, Reminder $reminder): RedirectResponse
    {
        abort_unless((int) $reminder->user_id === $request->user()->id, 403);
        $reminder->delete();

        return back();
    }
}

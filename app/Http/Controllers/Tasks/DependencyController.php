<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Services\Tasks\Dependencies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DependencyController extends Controller
{
    public function store(Request $request, Task $task): RedirectResponse
    {
        $this->authorizeRecord($task, 'tasks.edit');
        $data = $request->validate([
            'other_id' => ['required', 'integer', 'exists:tasks,id'],
            'type' => ['required', Rule::in(['waiting', 'blocking', 'link'])],
        ]);
        $other = Task::findOrFail($data['other_id']);
        $this->authorizeRecord($other, 'tasks.view');
        Dependencies::add($task, $other, $data['type'], $request->user()->id);

        return back();
    }

    public function destroy(Request $request, TaskDependency $dependency): RedirectResponse
    {
        abort_unless(($dependency->task && $dependency->task->isVisibleTo($request->user(), 'tasks.edit'))
            || ($dependency->dependsOn && $dependency->dependsOn->isVisibleTo($request->user(), 'tasks.edit')), 403);
        Dependencies::remove($dependency);

        return back();
    }

    /** Task picker: search visible tasks by title or id (ULF-12). */
    public function lookup(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q'));
        $query = Task::visibleTo($request->user())->with('client:id,task_prefix')->whereKeyNot((int) $request->input('exclude'));
        if (preg_match('/^([A-Za-z0-9]+)-(\d+)$/', $q, $m)) {
            $query->where('client_id', Client::withTrashed()->where('task_prefix', strtoupper($m[1]))->value('id') ?? 0)->where('seq', (int) $m[2]);
        } elseif ($q !== '') {
            $query->where('title', 'like', "%$q%");
        }

        return response()->json($query->latest('updated_at')->limit(15)->get()
            ->map(fn (Task $t) => ['id' => $t->id, 'code' => $t->code(), 'title' => $t->title, 'completed' => $t->completed_at !== null]));
    }
}

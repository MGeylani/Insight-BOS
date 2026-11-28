<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use App\Models\TaskList;
use App\Models\TaskStatus;
use App\Services\Tasks\TaskLists;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskListController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'project_milestone_id' => ['nullable', 'integer'],
            'scheme_template_id' => ['nullable', 'integer'],
        ]);
        $project = ! empty($data['project_id']) ? Project::findOrFail($data['project_id']) : null;
        $client = $project?->client ?? (! empty($data['client_id']) ? Client::findOrFail($data['client_id']) : TaskLists::internalClient());
        abort_unless(TaskList::canCreateFor($user, $client, $project), 403);

        $list = TaskLists::create($client, $project, $data, $user->id);

        return redirect("/tasks?list={$list->id}")->with('success', "تم إنشاء القائمة «{$list->name}».");
    }

    public function update(Request $request, TaskList $list): RedirectResponse
    {
        abort_unless($list->canManage($request->user()), 403);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'project_milestone_id' => ['sometimes', 'nullable', 'integer'],
        ]);
        TaskLists::update($list, $data);

        return back()->with('success', 'تم حفظ القائمة.');
    }

    public function destroy(Request $request, TaskList $list): RedirectResponse
    {
        abort_unless($list->canManage($request->user()), 403);
        $next = $list->project_id ? "/tasks?project={$list->project_id}" : "/tasks?client={$list->client_id}";
        TaskLists::delete($list);

        return redirect($next)->with('success', 'تم حذف القائمة.');
    }

    /** Replace the list's statuses (removed ones move their tasks to `move_to`). */
    public function statuses(Request $request, TaskList $list): RedirectResponse
    {
        abort_unless($list->canManage($request->user()), 403);
        $data = $this->validatedStatuses($request);
        TaskLists::saveStatuses($list->scheme, $data['statuses'], $data['move_to'] ?? []);

        return back()->with('success', 'تم حفظ حالات القائمة.');
    }

    /** @return array<string, mixed> */
    public static function validatedStatuses(Request $request): array
    {
        return $request->validate([
            'statuses' => ['required', 'array', 'min:1', 'max:30'],
            'statuses.*.id' => ['nullable', 'integer'],
            'statuses.*.name' => ['required', 'string', 'max:64'],
            'statuses.*.color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'statuses.*.group' => ['required', Rule::in(TaskStatus::GROUPS)],
            'move_to' => ['nullable', 'array'],
        ]);
    }
}

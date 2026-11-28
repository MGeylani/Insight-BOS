<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\TaskTemplate;
use App\Services\Tasks\Templates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TemplateController extends Controller
{
    /** Save a task, list or project's lists as a template. */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate(['from' => ['required', Rule::in(['task', 'list', 'project'])], 'id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:120']]);
        $tpl = match ($data['from']) {
            'task' => Templates::fromTask(tap(Task::findOrFail($data['id']), fn ($t) => $this->authorizeRecord($t, 'tasks.view')), $data['name'], $user),
            'list' => Templates::fromList(tap(TaskList::findOrFail($data['id']), fn ($l) => abort_unless($l->isVisibleTo($user), 403)), $data['name'], $user),
            'project' => Templates::fromProject(tap(Project::findOrFail($data['id']), fn ($p) => abort_unless($p->isVisibleTo($user), 403)), $data['name'], $user),
        };

        return back()->with('success', "حُفظ القالب «{$tpl->name}».");
    }

    public function apply(Request $request, TaskTemplate $template): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'list_id' => ['nullable', 'integer', 'exists:task_lists,id'],
            'parent_id' => ['nullable', 'integer', 'exists:tasks,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'name' => ['nullable', 'string', 'max:160'],
        ]);
        if ($template->type === 'task') {
            $parent = ! empty($data['parent_id']) ? Task::findOrFail($data['parent_id']) : null;
            $list = $parent?->list ?? TaskList::findOrFail($data['list_id'] ?? 0);
            abort_unless($list->isVisibleTo($user, 'tasks.create'), 403);
            $task = Templates::applyTask($template, $list, $user, $parent);

            return redirect("/tasks/{$task->id}")->with('success', "أُنشئت {$task->code()} من القالب.");
        }
        $project = ! empty($data['project_id']) ? Project::findOrFail($data['project_id']) : null;
        $client = $project?->client ?? (! empty($data['client_id']) ? Client::findOrFail($data['client_id']) : \App\Services\Tasks\TaskLists::internalClient());
        abort_unless(TaskList::canCreateFor($user, $client, $project), 403);
        if ($template->type === 'project') {
            abort_unless($project, 422, 'اختر مشروعاً.');
            Templates::applyProject($template, $project, $user);

            return redirect("/tasks?project={$project->id}")->with('success', 'أُضيفت قوائم القالب للمشروع.');
        }
        $list = Templates::applyList($template, $client, $project, $user, $data['name'] ?? null);

        return redirect("/tasks?list={$list->id}")->with('success', "أُنشئت القائمة «{$list->name}» من القالب.");
    }

    public function destroy(Request $request, TaskTemplate $template): RedirectResponse
    {
        abort_unless((int) $template->created_by === $request->user()->id || $request->user()->hasPermission('settings.manage'), 403);
        $template->delete();

        return back()->with('success', 'حُذف القالب.');
    }
}

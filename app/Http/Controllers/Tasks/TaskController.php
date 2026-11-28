<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Services\Storage\Attachments;
use App\Services\Tasks\Comments;
use App\Services\Tasks\TaskLists;
use App\Services\Tasks\TaskPresenter;
use App\Services\Tasks\TaskQuery;
use App\Services\Tasks\Tasks;
use App\Models\StatusScheme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    /** The task hub: location tree + tasks of the chosen client / project / list. */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $location = array_filter($request->only('list', 'project', 'client'));
        if (! empty($location['list'])) {
            $list = TaskList::findOrFail($location['list']);
            abort_unless($list->isVisibleTo($user), 403);
        }
        $filters = TaskQuery::clean($request->all());
        $tasks = TaskPresenter::rows(TaskQuery::build($user, $location, $filters));

        $listIds = ! empty($location['list']) ? collect([(int) $location['list']]) : $tasks->pluck('list_id')->unique();
        if (! empty($location['project'])) {
            $listIds = $listIds->merge(TaskList::where('project_id', $location['project'])->pluck('id'))->unique();
        }

        $projectId = $location['project'] ?? (isset($list) ? $list->project_id : null);

        return Inertia::render('tasks/Index', [
            'milestones' => $projectId ? \App\Models\ProjectMilestone::where('project_id', $projectId)->orderBy('sort')->get(['id', 'title']) : [],
            'tree' => TaskPresenter::tree($user),
            'location' => TaskPresenter::location($location),
            'tasks' => $tasks,
            'statuses' => TaskPresenter::statusesFor($listIds),
            'filters' => $filters,
            ...TaskPresenter::vocab(),
            'can' => $this->listCan($user, $location),
            'schemeTemplates' => StatusScheme::where('is_template', true)->orderByDesc('is_default')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, Task $task): Response
    {
        $user = $request->user();
        $this->authorizeRecord($task, 'tasks.view');
        $task->load([
            ...TaskPresenter::ROW_WITH, 'watchers:id,name', 'creator:id,name', 'parent.parent', 'otherLists.client:id,name', 'otherLists.project:id,name',
            'list.client:id,name,task_prefix', 'list.project:id,name,number', 'checklists.items.assignee:id,name',
        ]);
        $canEdit = $task->isVisibleTo($user, 'tasks.edit');

        $children = Task::where('parent_id', $task->id)->with(TaskPresenter::ROW_WITH)
            ->withCount(['children', 'children as children_done' => fn ($q) => $q->whereNotNull('completed_at')])
            ->orderBy('position')->orderBy('id')->get();
        $grand = $children->isEmpty() ? collect() : Task::whereIn('parent_id', $children->pluck('id'))->with(TaskPresenter::ROW_WITH)
            ->orderBy('position')->orderBy('id')->get()->groupBy('parent_id');

        $parents = [];
        for ($p = $task->parent; $p; $p = $p->relationLoaded('parent') ? $p->parent : null) {
            array_unshift($parents, ['id' => $p->id, 'code' => $p->code(), 'title' => $p->title]);
        }

        $sameClient = TaskList::visibleTo($user)->where('client_id', $task->client_id)->with('project:id,name')->orderBy('sort')->get();

        return Inertia::render('tasks/Show', [
            'task' => [
                ...TaskPresenter::row($task),
                'description' => $task->description,
                'watchers' => $task->watchers->map->only('id', 'name')->values(),
                'watching' => $task->watchers->contains('id', $user->id),
                'creator' => $task->creator?->only('id', 'name'),
                'parents' => $parents,
                'home' => [
                    'id' => $task->list?->id, 'name' => $task->list?->name,
                    'client' => $task->list?->client?->only('id', 'name'), 'project' => $task->list?->project?->only('id', 'name', 'number'),
                ],
                'other_lists' => $task->otherLists->map(fn (TaskList $l) => [
                    'id' => $l->id, 'name' => $l->name, 'client' => $l->client?->name, 'project' => $l->project?->name,
                ])->values(),
                'progress' => Tasks::progress($task),
                'children' => $children->map(fn (Task $c) => [
                    ...TaskPresenter::row($c),
                    'children' => ($grand[$c->id] ?? collect())->map(fn ($g) => TaskPresenter::row($g))->values(),
                ])->values(),
                'checklists' => $task->checklists->map(fn (Checklist $c) => [
                    'id' => $c->id, 'name' => $c->name,
                    'items' => $c->items->map(fn (ChecklistItem $i) => [
                        ...$i->only('id', 'parent_id', 'text', 'done', 'assignee_id'),
                        'assignee' => $i->assignee?->name,
                    ])->values(),
                ])->values(),
            ],
            'comments' => Comments::payload($task, $user),
            'files' => Attachments::payload($task, $user),
            'activity' => ActivityLog::with('user:id,name')->where('subject_type', 'task')->where('subject_id', $task->id)
                ->latest('id')->limit(100)->get()
                ->map(fn ($l) => ['id' => $l->id, 'action' => $l->action, 'description' => $l->description, 'user' => $l->user?->name, 'created_at' => $l->created_at?->toIso8601String()]),
            'statuses' => $task->list?->statuses()->get()->map->toUi()->values() ?? [],
            'milestones' => $task->project_id ? $task->project?->milestones()->get(['id', 'title'])->values() : [],
            'moveLists' => $sameClient->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'project' => $l->project?->name])->values(),
            'linkLists' => TaskList::visibleTo($user)->with(['client:id,name', 'project:id,name'])->orderBy('client_id')->orderBy('sort')->limit(500)->get()
                ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'client' => $l->client?->name, 'project' => $l->project?->name])->values(),
            ...TaskPresenter::vocab(),
            'can' => [
                'edit' => $canEdit,
                'assign' => $task->isVisibleTo($user, 'tasks.assign'),
                'delete' => $task->isVisibleTo($user, 'tasks.delete'),
                'create' => $user->hasPermission('tasks.create'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'task_list_id' => ['nullable', 'integer', 'exists:task_lists,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'parent_id' => ['nullable', 'integer', 'exists:tasks,id'],
            'title' => ['required', 'string', 'max:255'],
            'open' => ['nullable', 'boolean'],
            ...$this->fieldRules(),
        ]);

        if (! empty($data['parent_id'])) {
            $parent = Task::findOrFail($data['parent_id']);
            abort_unless($parent->isVisibleTo($user, 'tasks.edit') || $parent->isVisibleTo($user, 'tasks.create'), 403);
            $list = $parent->list;
        } elseif (! empty($data['task_list_id'])) {
            $list = TaskList::findOrFail($data['task_list_id']);
        } else {
            $client = ! empty($data['client_id']) ? Client::findOrFail($data['client_id']) : TaskLists::internalClient();
            abort_unless(TaskList::canCreateFor($user, $client, null) || $client->is_internal, 403);
            $list = TaskLists::defaultFor($client, $user->id);
        }
        abort_unless($list->isVisibleTo($user, 'tasks.create'), 403);

        if (! $user->hasPermission('tasks.assign')) {
            unset($data['assignees']); // defaults to the creator
        }
        $task = Tasks::create($list, $data, $user);

        return ! empty($data['open'])
            ? redirect("/tasks/{$task->id}")->with('success', "تم إنشاء المهمة {$task->code()}.")
            : back()->with('success', "تم إنشاء المهمة {$task->code()}.");
    }

    /** Change any subset of the task's fields (inline edits use this too). */
    public function update(Request $request, Task $task): RedirectResponse
    {
        $this->authorizeRecord($task, 'tasks.edit');
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            ...$this->fieldRules(),
        ]);
        $people = array_intersect_key($data, array_flip(['assignees', 'watchers', 'tags']));
        if (array_key_exists('assignees', $people)) {
            $this->authorizeRecord($task, 'tasks.assign');
        }
        Tasks::update($task, array_diff_key($data, $people));
        // Watchers first: new assignees are then added as watchers too.
        if (array_key_exists('watchers', $people)) {
            Tasks::syncPeople($task, 'watchers', $people['watchers'] ?? []);
        }
        if (array_key_exists('assignees', $people)) {
            Tasks::syncPeople($task, 'assignees', $people['assignees'] ?? []);
        }
        if (array_key_exists('tags', $people)) {
            Tasks::syncTags($task, $people['tags'] ?? []);
        }

        return back();
    }

    /** Follow / stop following: anyone who can see the task. */
    public function watch(Request $request, Task $task): RedirectResponse
    {
        $this->authorizeRecord($task, 'tasks.view');
        $user = $request->user();
        $task->watchers()->toggle([$user->id]);

        return back();
    }

    public function move(Request $request, Task $task): RedirectResponse
    {
        $this->authorizeRecord($task, 'tasks.edit');
        $data = $request->validate(['task_list_id' => ['required', 'integer', 'exists:task_lists,id']]);
        $list = TaskList::findOrFail($data['task_list_id']);
        abort_unless($list->isVisibleTo($request->user(), 'tasks.create'), 403);
        Tasks::moveToList($task, $list);

        return back()->with('success', "نُقلت المهمة إلى «{$list->name}».");
    }

    public function link(Request $request, Task $task): RedirectResponse
    {
        $this->authorizeRecord($task, 'tasks.edit');
        $data = $request->validate(['task_list_id' => ['required', 'integer', 'exists:task_lists,id']]);
        $list = TaskList::findOrFail($data['task_list_id']);
        abort_unless($list->isVisibleTo($request->user()), 403);
        Tasks::addToList($task, $list);

        return back()->with('success', "أصبحت المهمة تظهر أيضاً في «{$list->name}».");
    }

    public function unlink(Task $task, TaskList $list): RedirectResponse
    {
        $this->authorizeRecord($task, 'tasks.edit');
        Tasks::removeFromList($task, $list);

        return back();
    }

    public function parent(Request $request, Task $task): RedirectResponse
    {
        $this->authorizeRecord($task, 'tasks.edit');
        $data = $request->validate(['parent_id' => ['nullable', 'integer', 'exists:tasks,id']]);
        $parent = ! empty($data['parent_id']) ? Task::findOrFail($data['parent_id']) : null;
        if ($parent) {
            $this->authorizeRecord($parent, 'tasks.edit');
        }
        Tasks::setParent($task, $parent);

        return back();
    }

    /** Drag ordering: the neighbours' positions (fractional; nothing else is renumbered). */
    public function reorder(Request $request, Task $task): RedirectResponse
    {
        $this->authorizeRecord($task, 'tasks.edit');
        $data = $request->validate(['before' => ['nullable', 'numeric'], 'after' => ['nullable', 'numeric']]);
        Tasks::reorder($task, isset($data['before']) ? (float) $data['before'] : null, isset($data['after']) ? (float) $data['after'] : null);

        return back();
    }

    public function destroy(Task $task): RedirectResponse
    {
        $this->authorizeRecord($task, 'tasks.delete');
        $list = $task->task_list_id;
        Tasks::archive($task);

        return redirect(request()->boolean('stay') ? url()->previous() : "/tasks?list=$list")->with('success', "تمت أرشفة المهمة {$task->code()}.");
    }

    public function restore(int $id): RedirectResponse
    {
        $task = Task::onlyTrashed()->findOrFail($id);
        $this->authorizeRecord($task, 'tasks.delete');
        Tasks::restore($task);

        return redirect("/tasks/{$task->id}")->with('success', 'تمت استعادة المهمة.');
    }

    /** @return array<string, list<mixed>> */
    private function fieldRules(): array
    {
        return [
            'description' => ['sometimes', 'nullable'],
            'status_id' => ['sometimes', 'nullable', 'integer'],
            'priority' => ['sometimes', 'nullable', Rule::in(Task::PRIORITIES)],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'start_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'due_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'time_estimate_min' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'project_milestone_id' => ['sometimes', 'nullable', 'integer'],
            'assignees' => ['sometimes', 'nullable', 'array', 'max:30'],
            'assignees.*' => ['integer'],
            'watchers' => ['sometimes', 'nullable', 'array', 'max:50'],
            'watchers.*' => ['integer'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:30'],
            'tags.*' => ['integer'],
        ];
    }

    /** @return array<string, bool> */
    private function listCan(User $user, array $location): array
    {
        $create = $user->hasPermission('tasks.create');
        $manage = false;
        $newList = false;
        if (! empty($location['list'])) {
            $list = TaskList::find($location['list']);
            $create = $create && $list?->isVisibleTo($user, 'tasks.create');
            $manage = (bool) $list?->canManage($user);
        } elseif (! empty($location['project'])) {
            $project = Project::find($location['project']);
            $newList = $project && TaskList::canCreateFor($user, $project->client, $project);
        } elseif (! empty($location['client'])) {
            $client = Client::find($location['client']);
            $newList = $client && TaskList::canCreateFor($user, $client, null);
        } else {
            $newList = TaskList::canCreateFor($user, TaskLists::internalClient(), null);
        }

        return ['create' => $create, 'manage_list' => $manage, 'new_list' => $newList, 'edit' => $user->hasPermission('tasks.edit'),
            'assign' => $user->hasPermission('tasks.assign'), 'delete' => $user->hasPermission('tasks.delete')];
    }
}

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
use App\Services\Tasks\BulkActions;
use App\Services\Tasks\CustomFields;
use App\Services\Tasks\TimeTracking;
use App\Models\TimeEntry;
use App\Models\Reminder;
use App\Services\Tasks\TaskLists;
use App\Services\Tasks\ViewConfig;
use App\Models\SavedView;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
        $saved = $request->integer('saved') ? SavedView::availableTo($user, $this->locType($location), $this->locId($location))->find($request->integer('saved')) : null;
        // A saved view supplies the settings; anything in the URL overrides them (while adjusting a view).
        if ($saved) {
            $cfg = $saved->config ?? [];
            $filters = TaskQuery::clean($request->hasAny(TaskQuery::FILTER_KEYS) ? $request->all() : ($cfg['filters'] ?? []));
            $view = ViewConfig::clean(array_merge(['view' => $saved->type], $cfg, $request->only('view', 'group', 'sort', 'dir', 'columns', 'date', 'subtasks')));
        } else {
            $filters = TaskQuery::clean($request->all());
            $view = ViewConfig::clean($request->all());
        }
        $query = TaskQuery::sort(TaskQuery::build($user, $location, $filters), $view['sort'], $view['dir']);
        if (! $view['subtasks']) {
            $query->whereNull('tasks.parent_id');
        }
        $tasks = TaskPresenter::rows($query);

        $listIds = ! empty($location['list']) ? collect([(int) $location['list']]) : $tasks->pluck('list_id')->unique();
        if (! empty($location['project'])) {
            $listIds = $listIds->merge(TaskList::where('project_id', $location['project'])->pluck('id'))->unique();
        }

        $projectId = $location['project'] ?? (isset($list) ? $list->project_id : null);

        return Inertia::render('tasks/Index', [
            'milestones' => $projectId ? \App\Models\ProjectMilestone::where('project_id', $projectId)->orderBy('sort')->get(['id', 'title']) : [],
            'tree' => TaskPresenter::tree($user),
            'location' => $loc = TaskPresenter::location($location),
            'customFields' => CustomFields::forLocation($loc)->map->toUi()->values(),
            'canManageFields' => CustomFields::canManage($user, $loc['type'] ?? 'all', $loc['id'] ?? null),
            'tasks' => $tasks,
            'statuses' => TaskPresenter::statusesFor($listIds),
            'filters' => $filters,
            'view' => $view,
            'savedView' => $saved ? [...$saved->only('id', 'name', 'type', 'is_shared', 'location_type'), 'can_manage' => $saved->canManage($user)] : null,
            'savedViews' => SavedView::availableTo($user, $this->locType($location), $this->locId($location))->with('owner:id,name')
                ->orderBy('sort')->orderBy('id')->get()
                ->map(fn (SavedView $v) => [...$v->only('id', 'name', 'type', 'is_shared', 'location_type'), 'owner' => $v->owner?->name, 'mine' => (int) $v->owner_id === $user->id])->values(),
            'columns' => ViewConfig::COLUMNS,
            'lists' => TaskList::visibleTo($user, 'tasks.create')->with(['client:id,name', 'project:id,name'])->orderBy('client_id')->orderBy('sort')->limit(500)->get()
                ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'client_id' => $l->client_id, 'client' => $l->client?->name, 'project' => $l->project?->name])->values(),
            ...TaskPresenter::vocab(),
            'can' => $this->listCan($user, $location),
            'schemeTemplates' => StatusScheme::where('is_template', true)->orderByDesc('is_default')->get(['id', 'name']),
        ]);
    }

    /** The same change on many tasks (status, people, dates, tags, list, archive). */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(array_keys(BulkActions::ACTIONS))],
            'value' => ['nullable'],
        ]);
        $r = BulkActions::run($request->user(), array_map('intval', $data['ids']), $data['action'], $data['value'] ?? null);

        return back()->with($r['done'] ? 'success' : 'error', "تم التطبيق على {$r['done']} مهمة".($r['skipped'] ? " (تخطّي {$r['skipped']} لا تملك صلاحيتها أو لا ينطبق عليها)" : '').'.');
    }

    /** CSV (Excel opens it) of the current view: same location, filters and sorting. */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        $location = array_filter($request->only('list', 'project', 'client'));
        $view = ViewConfig::clean($request->all());
        $rows = TaskPresenter::rows(TaskQuery::sort(TaskQuery::build($user, $location, TaskQuery::clean($request->all())), $view['sort'], $view['dir']), 5000);
        $priorities = config('tasks.priorities');
        $head = ['المعرّف', 'العنوان', 'المهمة الأم', 'العميل', 'المشروع', 'القائمة', 'الحالة', 'الأولوية', 'المسؤولون', 'البدء', 'التسليم', 'التقدير (ساعة)', 'الوسوم', 'أُنشئت', 'اكتملت'];
        $codes = $rows->pluck('code', 'id');
        // Custom field columns chosen in the table view.
        $cfIds = collect($view['columns'])->filter(fn ($c) => str_starts_with($c, 'cf:'))->map(fn ($c) => (int) substr($c, 3));
        $cfs = \App\Models\CustomField::whereIn('id', $cfIds)->get()->sortBy(fn ($f) => $cfIds->search($f->id))->values();
        $head = array_merge($head, $cfs->pluck('name')->all());
        \App\Services\ActivityLogger::log('tasks.exported', null, 'تصدير '.count($rows).' مهمة');

        return response()->streamDownload(function () use ($rows, $head, $priorities, $codes, $cfs) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Arabic correctly
            fputcsv($out, $head);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['code'], $r['title'], $r['parent_id'] ? ($codes[$r['parent_id']] ?? '#'.$r['parent_id']) : '',
                    $r['client']['name'] ?? '', $r['project']['name'] ?? '', $r['list']['name'] ?? '',
                    $r['status']['name'] ?? '', $priorities[$r['priority']] ?? $r['priority'],
                    collect($r['assignees'])->pluck('name')->implode('، '),
                    str_replace('T', ' ', (string) $r['start']), str_replace('T', ' ', (string) $r['due']),
                    $r['estimate'] ? round($r['estimate'] / 60, 2) : '',
                    collect($r['tags'])->pluck('name')->implode('، '),
                    substr((string) $r['created_at'], 0, 10), substr((string) $r['completed_at'], 0, 10),
                    ...$cfs->map(fn ($f) => CustomFields::display($f, ((array) $r['fields'])[$f->id] ?? null))->all(),
                ]);
            }
            fclose($out);
        }, 'tasks-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
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
            'dependencies' => \App\Services\Tasks\Dependencies::payload($task),
            'customFields' => CustomFields::forTask($task)->map->toUi()->values(),
            'fieldValues' => (object) (CustomFields::valuesFor(collect([$task]))[$task->id] ?? []),
            'time' => $this->timePayload($task, $user),
            'reminders' => Reminder::where('user_id', $user->id)->where('task_id', $task->id)->whereNull('done_at')->orderBy('remind_at')->get()
                ->map(fn ($r) => ['id' => $r->id, 'remind_at' => $r->remind_at->format('Y-m-d\TH:i'), 'note' => $r->note, 'sent' => $r->sent_at !== null]),
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
                'track' => $user->hasPermission('tasks.track_time'),
            ],
        ]);
    }

    /** Time on this task: everyone's total, and the entries the user may see (own, or all with track_time = all). */
    private function timePayload(Task $task, User $user): array
    {
        $all = $user->permissionScope('tasks.track_time') === 'all';
        $entries = TimeEntry::with('user:id,name')->where('task_id', $task->id)->when(! $all, fn ($q) => $q->where('user_id', $user->id))
            ->latest('started_at')->limit(50)->get();

        return [
            'total' => TimeTracking::totals(TimeEntry::where('task_id', $task->id))['minutes'],
            'mine' => TimeTracking::totals(TimeEntry::where('task_id', $task->id)->where('user_id', $user->id))['minutes'],
            'entries' => $entries->map(fn (TimeEntry $e) => [
                'id' => $e->id, 'user' => $e->user?->name, 'date' => $e->started_at->toDateString(), 'from' => $e->started_at->format('H:i'),
                'minutes' => $e->liveMinutes(), 'running' => $e->ended_at === null, 'billable' => $e->billable, 'note' => $e->note,
                'can_edit' => $all || (int) $e->user_id === $user->id,
            ])->values(),
        ];
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
            'task_type_id' => ['sometimes', 'nullable', 'integer'],
            'recurrence' => ['sometimes', 'nullable', 'array'],
            'force' => ['sometimes', 'boolean'],
            'assignees' => ['sometimes', 'nullable', 'array', 'max:30'],
            'assignees.*' => ['integer'],
            'watchers' => ['sometimes', 'nullable', 'array', 'max:50'],
            'watchers.*' => ['integer'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:30'],
            'tags.*' => ['integer'],
        ];
    }

    private function locType(array $location): ?string
    {
        foreach (['list', 'project', 'client'] as $k) {
            if (! empty($location[$k])) {
                return $k;
            }
        }

        return null;
    }

    private function locId(array $location): ?int
    {
        $t = $this->locType($location);

        return $t ? (int) $location[$t] : null;
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

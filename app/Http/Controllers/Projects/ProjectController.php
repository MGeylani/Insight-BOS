<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ChangeRequest;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Currency;
use App\Models\Project;
use App\Models\ProjectIssue;
use App\Models\ProjectMilestone;
use App\Models\ProjectRisk;
use App\Models\Quote;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Projects\Projects;
use App\Services\Storage\Attachments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $f = $request->only('search', 'status', 'type', 'manager', 'client');

        $projects = Project::visibleTo($user)->with(['client:id,name', 'manager:id,name',
            'milestones' => fn ($q) => ProjectMilestone::withTaskCounts($q->select('id', 'project_id', 'status'))])
            ->withCount(['issues as open_issues_count' => fn ($q) => $q->where('status', '!=', 'resolved')])
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%$s%")->orWhere('number', 'like', "%$s%")
                ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%$s%"))))
            ->when($f['status'] ?? null, function ($q, $st) {
                match ($st) {
                    'overdue' => $q->overdue(),
                    'open' => $q->open(),
                    default => $q->where('status', $st),
                };
            }, fn ($q) => $q->orderByRaw("case when status in ('planning','active','on_hold') then 0 else 1 end"))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['manager'] ?? null, fn ($q, $v) => $q->where('manager_id', $v))
            ->when($f['client'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
            ->orderByRaw('due_date is null')->orderBy('due_date')->latest('id')
            ->paginate(30)->withQueryString();

        $visible = fn () => Project::visibleTo($user);
        $seeBudget = $this->canSeeBudget($user);

        return Inertia::render('projects/Index', [
            'projects' => $projects->through(fn (Project $p) => $this->row($p, $seeBudget)),
            'summary' => [
                'active' => $visible()->where('status', 'active')->count(),
                'planning' => $visible()->where('status', 'planning')->count(),
                'overdue' => $visible()->overdue()->count(),
                'on_hold' => $visible()->where('status', 'on_hold')->count(),
                'open_issues' => ProjectIssue::where('status', '!=', 'resolved')->whereIn('project_id', $visible()->open()->select('id'))->count(),
                'pending_changes' => ChangeRequest::where('status', 'pending')->whereIn('project_id', $visible()->select('id'))->count(),
            ],
            'filters' => $f,
            'seeBudget' => $seeBudget,
            ...$this->vocab(),
            'users' => $this->users(),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();
        $form = [
            'name' => '', 'type' => 'identity', 'template' => 'identity', 'client_id' => null, 'quote_id' => null, 'contract_id' => null,
            'manager_id' => $user->id, 'status' => 'planning', 'currency' => 'USD', 'budget' => null,
            'start_date' => today()->toDateString(), 'due_date' => null, 'description' => '', 'members' => [],
        ];

        if ($quoteId = $request->integer('quote_id')) {
            $quote = Quote::with('items')->findOrFail($quoteId);
            $this->authorizeRecord($quote, 'crm.view');
            $form = array_merge($form, array_filter(Projects::fromQuote($quote), fn ($v) => $v !== null));
        } elseif ($contractId = $request->integer('contract_id')) {
            $contract = Contract::with('schedules')->findOrFail($contractId);
            $this->authorizeRecord($contract, 'crm.view');
            $form = array_merge($form, array_filter(Projects::fromContract($contract), fn ($v) => $v !== null));
        } elseif ($clientId = $request->integer('client_id')) {
            $form['client_id'] = $clientId;
            $form['currency'] = Client::find($clientId)?->currency ?? 'USD';
        }
        $form['template'] = config("projects.templates.{$form['type']}") ? $form['type'] : null;
        if (! $form['due_date'] && $form['template'] && $form['start_date']) {
            $last = collect(config("projects.templates.{$form['type']}.milestones"))->max('offset_days');
            $form['due_date'] = Carbon::parse($form['start_date'])->addDays((int) $last)->toDateString();
        }

        return $this->editor($request, null, $form);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        [$data, $members, $template] = $this->validated($request, true);
        $client = Client::findOrFail($data['client_id']);
        abort_unless($client->isVisibleTo($user, 'crm.view') || $user->permissionScope('projects.create') === 'all', 403);

        if (! $user->hasPermission('projects.manage_budget')) {
            $data['budget'] = $this->sourceBudget($data);
        }
        $project = Projects::create($data, $members, $template, $user->id);

        return redirect("/projects/{$project->id}")->with('success', "تم إنشاء المشروع {$project->number}.");
    }

    public function show(Request $request, Project $project): Response
    {
        $this->authorizeRecord($project, 'projects.view');
        $user = $request->user();
        $project->load([
            'client:id,name', 'quote:id,number,title', 'contract:id,number,title,type', 'manager:id,name', 'creator:id,name', 'members:id,name',
            'milestones' => fn ($q) => ProjectMilestone::withTaskCounts($q->with('doneBy:id,name')), 'risks.owner:id,name', 'issues.owner:id,name', 'issues.creator:id,name',
            'changeRequests.creator:id,name', 'changeRequests.decider:id,name',
        ]);
        $seeBudget = $this->canSeeBudget($user);
        $log = ActivityLog::with('user:id,name')->where('subject_type', 'project')->where('subject_id', $project->id)->latest('id')->limit(50)->get();
        $approved = $project->changeRequests->where('status', 'approved');

        return Inertia::render('projects/Show', [
            'project' => [
                ...$this->row($project, $seeBudget),
                ...$project->only('client_id', 'manager_id', 'quote_id', 'contract_id', 'description', 'template'),
                'quote' => $project->quote?->only('id', 'number', 'title'),
                'contract' => $project->contract?->only('id', 'number', 'title', 'type'),
                'creator' => $project->creator?->only('id', 'name'),
                'created_at' => $project->created_at?->toIso8601String(),
                'completed_at' => $project->completed_at?->toIso8601String(),
                'members' => $project->members->map(fn (User $m) => ['id' => $m->id, 'name' => $m->name, 'role' => $m->pivot->role])->values(),
                'approved_cost' => $seeBudget ? round($approved->sum(fn ($c) => (float) $c->cost_impact), 2) : null,
                'approved_days' => (int) $approved->sum('days_impact'),
                // Logged hours feed the project's actual cost (minutes x the person's hourly cost, USD).
                'time' => (fn ($t) => [...$t, 'cost' => $seeBudget ? $t['cost'] : null, 'uncosted_minutes' => $seeBudget ? $t['uncosted_minutes'] : null])(
                    \App\Services\Tasks\TimeTracking::forProject($project)),
            ],
            'milestones' => $project->milestones->map(fn (ProjectMilestone $m) => [
                ...$m->only('id', 'title', 'details', 'sort'),
                'status' => $m->displayStatus(),
                'progress' => $m->progress(),
                'tasks_total' => (int) ($m->tasks_total ?? 0),
                'tasks_done' => (int) ($m->tasks_done ?? 0),
                'due_date' => $m->due_date?->toDateString(),
                'done_at' => $m->done_at?->toIso8601String(),
                'done_by' => $m->doneBy?->name,
            ])->values(),
            'risks' => $project->risks->map(fn (ProjectRisk $r) => [
                ...$r->only('id', 'title', 'description', 'category', 'probability', 'impact', 'mitigation', 'owner_id', 'status'),
                'score' => $r->score(), 'level' => $r->level(),
                'owner' => $r->owner?->only('id', 'name'),
            ])->sortByDesc(fn ($r) => [$r['status'] === 'open' ? 1 : 0, $r['score']])->values(),
            'issues' => $project->issues->map(fn (ProjectIssue $i) => [
                ...$i->only('id', 'title', 'description', 'severity', 'owner_id', 'status', 'resolution'),
                'due_date' => $i->due_date?->toDateString(),
                'resolved_at' => $i->resolved_at?->toIso8601String(),
                'overdue' => $i->isOverdue(),
                'owner' => $i->owner?->only('id', 'name'),
                'creator' => $i->creator?->name,
                'created_at' => $i->created_at?->toIso8601String(),
            ])->values(),
            'changes' => $project->changeRequests->map(fn (ChangeRequest $c) => [
                ...$c->only('id', 'seq', 'title', 'description', 'source', 'days_impact', 'status', 'decision_note'),
                'code' => $c->code(),
                'cost_impact' => (float) $c->cost_impact,
                'creator' => $c->creator?->name,
                'created_at' => $c->created_at?->toIso8601String(),
                'decider' => $c->decider?->name,
                'decided_at' => $c->decided_at?->toIso8601String(),
            ])->values(),
            'log' => $log->map(fn ($l) => [
                'id' => $l->id, 'description' => $l->description,
                'user' => $l->user?->name, 'created_at' => $l->created_at?->toIso8601String(),
            ]),
            'files' => Attachments::payload($project, $user),
            'taskLists' => $user->hasPermission('tasks.view') ? $project->taskLists()
                ->withCount(['tasks as total' => fn ($q) => $q->whereNull('parent_id'), 'tasks as done' => fn ($q) => $q->whereNull('parent_id')->whereNotNull('completed_at')])
                ->get()->map(fn ($l) => [...$l->only('id', 'name', 'project_milestone_id'), 'total' => (int) $l->total, 'done' => (int) $l->done])->values() : null,
            'can' => [
                'edit' => $project->isVisibleTo($user, 'projects.edit'),
                'delete' => $project->isVisibleTo($user, 'projects.delete'),
                'budget' => $project->isVisibleTo($user, 'projects.manage_budget'),
                'approve' => $project->isVisibleTo($user, 'projects.approve_changes'),
                'see_budget' => $seeBudget,
            ],
            ...$this->vocab(),
            'users' => $this->users(),
        ]);
    }

    public function edit(Request $request, Project $project): Response
    {
        $this->authorizeRecord($project, 'projects.edit');
        $project->load('members:id');

        return $this->editor($request, $project, [
            ...$project->only('name', 'type', 'client_id', 'quote_id', 'contract_id', 'manager_id', 'status', 'currency'),
            'template' => null,
            'budget' => $project->budget === null ? null : (float) $project->budget,
            'start_date' => $project->start_date?->toDateString(),
            'due_date' => $project->due_date?->toDateString(),
            'description' => $project->description ?? '',
            'members' => $project->members->map(fn (User $m) => ['user_id' => $m->id, 'role' => $m->pivot->role ?? ''])->values(),
        ]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeRecord($project, 'projects.edit');
        [$data, $members] = $this->validated($request, false);
        unset($data['client_id']); // a project never moves to another client
        if (! $project->isVisibleTo($request->user(), 'projects.manage_budget')) {
            unset($data['budget'], $data['currency']);
        }
        Projects::update($project, $data);
        Projects::syncMembers($project, $members);

        return redirect("/projects/{$project->id}")->with('success', 'تم حفظ المشروع.');
    }

    /** Quick status change from the workspace header. */
    public function status(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeRecord($project, 'projects.edit');
        $data = $request->validate(['status' => ['required', Rule::in(Project::STATUSES)]]);
        Projects::update($project, $data);

        return back()->with('success', 'تم تحديث حالة المشروع.');
    }

    public function destroy(Project $project): RedirectResponse
    {
        $this->authorizeRecord($project, 'projects.delete');
        $project->delete();
        ActivityLogger::log('projects.archived', $project, "أرشفة المشروع {$project->label()}");

        return redirect('/projects')->with('success', 'تمت أرشفة المشروع.');
    }

    /** @param array<string, mixed> $form */
    private function editor(Request $request, ?Project $project, array $form): Response
    {
        $user = $request->user();
        $with = [
            'quotes' => fn ($q) => $q->where('status', 'accepted')->select('id', 'client_id', 'number', 'title', 'project_id'),
            'contracts' => fn ($q) => $q->whereIn('status', ['sent', 'active'])->select('id', 'client_id', 'number', 'title', 'project_id'),
        ];
        $clients = Client::visibleTo($user)->orderBy('name')->limit(1000)->with($with)->get(['id', 'name', 'currency']);
        if ($form['client_id'] && ! $clients->contains('id', $form['client_id'])) {
            $c = Client::with($with)->find($form['client_id'], ['id', 'name', 'currency']);
            $c && $clients->prepend($c);
        }
        $free = fn ($row) => ! $row->project_id || ($project && (int) $row->project_id === $project->id);

        return Inertia::render('projects/Form', [
            'project' => $project?->only('id', 'name', 'number'),
            'form' => $form,
            'clients' => $clients->map(fn (Client $c) => [
                ...$c->only('id', 'name', 'currency'),
                'quotes' => $c->quotes->filter($free)->map->only('id', 'number', 'title')->values(),
                'contracts' => $c->contracts->filter($free)->map->only('id', 'number', 'title')->values(),
            ]),
            'templates' => collect(config('projects.templates'))->map(fn ($t, $k) => [
                'milestones' => count($t['milestones'] ?? []), 'risks' => count($t['risks'] ?? []),
                'days' => (int) collect($t['milestones'] ?? [])->max('offset_days'),
            ]),
            'canBudget' => $project ? $project->isVisibleTo($user, 'projects.manage_budget') : $user->hasPermission('projects.manage_budget'),
            ...$this->vocab(),
            'users' => $this->users(),
            'currencies' => Currency::where('is_active', true)->orderByDesc('is_base')->pluck('code'),
        ]);
    }

    /** Budget taken from the linked quote or contract, for people who cannot set it themselves. @param array<string, mixed> $data */
    private function sourceBudget(array $data): ?float
    {
        if (! empty($data['contract_id']) && ($c = Contract::find($data['contract_id']))) {
            return $c->totalValue() ?: null;
        }
        if (! empty($data['quote_id']) && ($q = Quote::find($data['quote_id']))) {
            return $q->totals()['net'] ?: null;
        }

        return null;
    }

    private function canSeeBudget(User $user): bool
    {
        return $user->hasPermission('projects.manage_budget') || $user->hasPermission('projects.approve_changes') || $user->hasPermission('finance.view');
    }

    /** @return array<string, mixed> */
    private function row(Project $p, bool $seeBudget): array
    {
        return [
            ...$p->only('id', 'number', 'name', 'type', 'status', 'currency'),
            'overdue' => $p->isOverdue(),
            'client' => $p->client?->only('id', 'name'),
            'manager' => $p->manager?->only('id', 'name'),
            'start_date' => $p->start_date?->toDateString(),
            'due_date' => $p->due_date?->toDateString(),
            'progress' => $p->progress(),
            'open_issues' => $p->open_issues_count ?? null,
            'budget' => $seeBudget && $p->budget !== null ? (float) $p->budget : null,
            'budget_usd' => $seeBudget ? $p->budgetInBase() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function vocab(): array
    {
        return [
            'types' => config('projects.types'),
            'statuses' => config('projects.statuses'),
            'memberRoles' => config('projects.member_roles'),
            'riskLevels' => config('projects.risk_levels'),
            'riskCategories' => config('projects.risk_categories'),
            'riskStatuses' => config('projects.risk_statuses'),
            'issueSeverities' => config('projects.issue_severities'),
            'issueStatuses' => config('projects.issue_statuses'),
            'changeSources' => config('projects.change_sources'),
            'changeStatuses' => config('projects.change_statuses'),
        ];
    }

    private function users()
    {
        return User::where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /** @return array{0: array<string, mixed>, 1: list<array<string, mixed>>, 2: ?string} */
    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'type' => ['required', Rule::in(array_keys(config('projects.types')))],
            'template' => ['nullable', Rule::in(array_keys(config('projects.templates')))],
            'client_id' => [$creating ? 'required' : 'nullable', 'exists:clients,id'],
            'quote_id' => ['nullable', 'exists:quotes,id'],
            'contract_id' => ['nullable', 'exists:contracts,id'],
            'manager_id' => ['nullable', 'exists:users,id'],
            'status' => ['required', Rule::in(Project::STATUSES)],
            'currency' => ['required', 'exists:currencies,code'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'description' => ['nullable', 'string', 'max:10000'],
            'members' => ['array', 'max:50'],
            'members.*.user_id' => ['required', 'distinct', 'exists:users,id'],
            'members.*.role' => ['nullable', 'string', 'max:64'],
        ], [
            'due_date.after_or_equal' => 'تاريخ التسليم يجب أن يكون بعد تاريخ البدء.',
            'members.*.user_id.distinct' => 'هذا الشخص مضاف مرتين.',
        ]);
        $members = array_values($data['members'] ?? []);
        $template = $data['template'] ?? null;
        unset($data['members'], $data['template']);

        return [$data, $members, $template];
    }
}

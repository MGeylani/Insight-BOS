<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Automation;
use App\Models\CustomField;
use App\Models\TaskList;
use App\Models\TaskStatus;
use App\Models\TaskTemplate;
use App\Services\ActivityLogger;
use App\Services\Tasks\Automations;
use App\Services\Tasks\CustomFields;
use App\Services\Tasks\TaskPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Automations of a location (or company-wide). Managed by whoever may manage its custom fields. */
class AutomationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $loc = TaskPresenter::location(array_filter($request->only('list', 'project', 'client')));
        abort_unless(CustomFields::canManage($user, $loc['type'] ?? 'all', $loc['id'] ?? null), 403);
        [$clientId, $projectId, $listId] = CustomFields::chain($loc);
        $autos = Automation::query()->when($loc, fn ($q) => $q->where('scope_type', $loc['type'])->where('scope_id', $loc['id']), fn ($q) => $q->where('scope_type', 'all'))
            ->with(['runs' => fn ($q) => $q->limit(5)])->orderBy('id')->get();

        return Inertia::render('tasks/Automations', [
            'location' => $loc,
            'automations' => $autos->map(fn (Automation $a) => [
                ...$a->only('id', 'name', 'trigger', 'trigger_config', 'conditions', 'actions', 'is_active', 'runs_count'),
                'last_run_at' => $a->last_run_at?->toIso8601String(),
                'runs' => $a->runs->take(5)->map(fn ($r) => [...$r->only('id', 'status', 'message', 'task_id'), 'created_at' => $r->created_at?->toIso8601String()])->values(),
            ]),
            'triggers' => Automations::TRIGGERS,
            'actions' => Automations::ACTIONS,
            'recipes' => Automations::recipes(),
            'statuses' => TaskStatus::whereIn('status_scheme_id', TaskList::when($listId, fn ($q) => $q->whereKey($listId))
                ->when(! $listId && $projectId, fn ($q) => $q->where('project_id', $projectId))
                ->when(! $listId && ! $projectId && $clientId, fn ($q) => $q->where('client_id', $clientId))->select('status_scheme_id'))
                ->orWhereIn('status_scheme_id', \App\Models\StatusScheme::where('is_template', true)->select('id'))
                ->orderBy('sort')->get()->unique('name')->map->toUi()->values(),
            'fields' => CustomFields::forLocation($loc)->map->toUi()->values(),
            'templates' => TaskTemplate::orderBy('name')->get(['id', 'name', 'type']),
            'lists' => TaskList::visibleTo($user)->with('client:id,name')->orderBy('client_id')->limit(300)->get()->map(fn ($l) => ['id' => $l->id, 'name' => $l->name.' · '.$l->client?->name]),
            ...TaskPresenter::vocab(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        abort_unless(CustomFields::canManage($request->user(), $data['scope_type'], $data['scope_id'] ?? null), 403);
        $a = Automation::create([...$data, ...Automations::clean($request->all()), 'created_by' => $request->user()->id]);
        ActivityLogger::log('tasks.automation_saved', $a, "إضافة أتمتة «{$a->name}»");

        return back()->with('success', 'حُفظت الأتمتة.');
    }

    public function update(Request $request, Automation $automation): RedirectResponse
    {
        abort_unless(CustomFields::canManage($request->user(), $automation->scope_type, $automation->scope_id), 403);
        if ($request->has('is_active') && ! $request->has('trigger')) {
            $automation->update(['is_active' => $request->boolean('is_active')]);

            return back();
        }
        $data = $this->validated($request);
        $automation->update([...array_diff_key($data, ['scope_type' => 1, 'scope_id' => 1]), ...Automations::clean($request->all())]);

        return back()->with('success', 'حُفظت الأتمتة.');
    }

    public function destroy(Request $request, Automation $automation): RedirectResponse
    {
        abort_unless(CustomFields::canManage($request->user(), $automation->scope_type, $automation->scope_id), 403);
        $automation->delete();

        return back()->with('success', 'حُذفت الأتمتة.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scope_type' => ['required', Rule::in(['all', 'client', 'project', 'list'])],
            'scope_id' => ['nullable', 'integer'],
            'trigger' => ['required', Rule::in(array_keys(Automations::TRIGGERS))],
            'trigger_config' => ['nullable', 'array'],
            'conditions' => ['nullable', 'array'],
            'actions' => ['required', 'array', 'min:1', 'max:10'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}

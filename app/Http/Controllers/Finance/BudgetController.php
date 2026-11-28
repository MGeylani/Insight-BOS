<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\CostCenter;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Project;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Finance\Budgets;
use App\Services\Finance\Payables;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Budgets and cost centers. Finance (view all) sees every budget; project managers see and manage their projects' budgets. */
class BudgetController extends Controller
{
    private static function visible(User $u)
    {
        $q = Budget::with(['project:id,name,number,manager_id,created_by', 'costCenter:id,name', 'owner:id,name']);
        if ($u->permissionScope('finance.view') === 'all') {
            return $q;
        }

        return $q->where(fn ($q) => $q->where('owner_id', $u->id)
            ->orWhereIn('project_id', Project::visibleTo($u, 'projects.manage_budget')->select('projects.id')));
    }

    public function index(Request $request): Response
    {
        $u = $request->user();
        abort_unless($u->hasPermission('finance.view') || $u->hasPermission('projects.manage_budget'), 403);
        $budgets = self::visible($u)
            ->when($request->input('scope') === 'project', fn ($q) => $q->whereNotNull('project_id'))
            ->when($request->input('scope') === 'center', fn ($q) => $q->whereNotNull('cost_center_id'))
            ->when($request->input('scope') === 'company', fn ($q) => $q->whereNull('project_id')->whereNull('cost_center_id'))
            ->when($request->boolean('current', true), fn ($q) => $q->whereDate('period_end', '>=', today()))
            ->orderBy('period_start', 'desc')->get();

        return Inertia::render('finance/budgets/Index', [
            'budgets' => $budgets->map(fn (Budget $b) => self::row($b) + ['report' => collect(Budgets::report($b))->except('lines')->all()]),
            'costCenters' => CostCenter::withTrashed()->orderBy('name')->get()->map(fn ($c) => [...$c->only('id', 'name', 'code', 'is_active'), 'archived' => $c->trashed()]),
            'filters' => ['scope' => $request->input('scope', ''), 'current' => $request->boolean('current', true)],
            'can' => [
                'create' => $u->permissionScope('finance.edit') === 'all' || $u->hasPermission('projects.manage_budget'),
                'centers' => $u->permissionScope('finance.edit') === 'all' || $u->hasPermission('settings.manage'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $project = $request->integer('project_id') ? Project::findOrFail($request->integer('project_id')) : null;
        abort_unless(Budgets::canManage($request->user(), $project) || $request->user()->hasPermission('projects.manage_budget'), 403);

        return $this->form($request, new Budget([
            'project_id' => $project?->id, 'period_start' => $project?->start_date ?? today()->startOfMonth(),
            'period_end' => $project?->due_date ?? today()->endOfYear(), 'alert_percent' => 90, 'owner_id' => $project?->manager_id ?? $request->user()->id,
            'name' => $project ? 'ميزانية '.$project->name : '',
        ]));
    }

    public function edit(Request $request, Budget $budget): Response
    {
        abort_unless(Budgets::canManage($request->user(), $budget->project), 403);

        return $this->form($request, $budget->load('lines'));
    }

    private function form(Request $request, Budget $b): Response
    {
        $u = $request->user();
        $all = $u->permissionScope('finance.edit') === 'all';

        return Inertia::render('finance/budgets/Form', [
            'budget' => [
                ...$b->only('id', 'name', 'project_id', 'cost_center_id', 'alert_percent', 'notes', 'owner_id'),
                'period_start' => $b->period_start?->toDateString(), 'period_end' => $b->period_end?->toDateString(),
                'lines' => $b->exists ? $b->lines->map(fn ($l) => ['kind' => $l->kind, 'category_id' => $l->category_id, 'planned' => (float) $l->planned])->all() : [],
            ],
            'projects' => ($all ? Project::query() : Project::visibleTo($u, 'projects.manage_budget'))->orderByDesc('id')->get(['projects.id', 'number', 'name']),
            'costCenters' => $all ? CostCenter::where('is_active', true)->orderBy('name')->get(['id', 'name']) : [],
            'categories' => ExpenseCategory::where('is_active', true)->orderBy('sort')->orderBy('name')->get(['id', 'name', 'kind']),
            'people' => Payables::people(),
            'companyWide' => $all,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$data, $lines] = $this->validated($request);
        abort_unless(Budgets::canManage($request->user(), isset($data['project_id']) ? Project::find($data['project_id']) : null), 403);
        $b = Budgets::save(new Budget, $data, $lines, $request->user());

        return redirect("/finance/budgets/{$b->id}")->with('success', 'تم إنشاء الميزانية.');
    }

    public function update(Request $request, Budget $budget): RedirectResponse
    {
        abort_unless(Budgets::canManage($request->user(), $budget->project), 403);
        [$data, $lines] = $this->validated($request);
        abort_unless(Budgets::canManage($request->user(), isset($data['project_id']) ? Project::find($data['project_id']) : null), 403);
        Budgets::save($budget, $data, $lines, $request->user());

        return redirect("/finance/budgets/{$budget->id}")->with('success', 'تم حفظ الميزانية.');
    }

    public function show(Request $request, Budget $budget): Response
    {
        $u = $request->user();
        abort_unless(Budgets::canView($u, $budget), 403);
        $budget->load(['project:id,name,number,manager_id', 'costCenter:id,name', 'owner:id,name']);
        $expenses = Budgets::expensesQuery($budget)->with(['category:id,name', 'user:id,name', 'vendor:id,name'])->latest('date')->limit(100)->get();

        return Inertia::render('finance/budgets/Show', [
            'budget' => self::row($budget) + ['notes' => $budget->notes],
            'report' => Budgets::report($budget),
            'expenses' => $expenses->map(fn (Expense $e) => ExpenseController::row($e)),
            'can' => ['edit' => Budgets::canManage($u, $budget->project)],
        ]);
    }

    public function destroy(Request $request, Budget $budget): RedirectResponse
    {
        abort_unless(Budgets::canManage($request->user(), $budget->project), 403);
        $budget->delete();
        ActivityLogger::log('finance.budget_deleted', $budget, "حذف ميزانية «{$budget->name}»");

        return redirect('/finance/budgets')->with('success', 'حُذفت الميزانية.');
    }

    // Cost centers.
    public function storeCenter(Request $request): RedirectResponse
    {
        $this->authorizeCenters($request);
        CostCenter::create($request->validate(['name' => ['required', 'string', 'max:120'], 'code' => ['nullable', 'string', 'max:20'], 'is_active' => ['boolean']]));

        return back()->with('success', 'تمت إضافة مركز التكلفة.');
    }

    public function updateCenter(Request $request, CostCenter $center): RedirectResponse
    {
        $this->authorizeCenters($request);
        $center->update($request->validate(['name' => ['required', 'string', 'max:120'], 'code' => ['nullable', 'string', 'max:20'], 'is_active' => ['boolean']]));

        return back()->with('success', 'تم الحفظ.');
    }

    public function destroyCenter(Request $request, CostCenter $center): RedirectResponse
    {
        $this->authorizeCenters($request);
        $center->delete();

        return back()->with('success', 'أُرشف مركز التكلفة.');
    }

    private function authorizeCenters(Request $request): void
    {
        $u = $request->user();
        abort_unless($u->permissionScope('finance.edit') === 'all' || $u->hasPermission('settings.manage'), 403);
    }

    /** @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} */
    private function validated(Request $request): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'project_id' => ['nullable', 'exists:projects,id'],
            'cost_center_id' => ['nullable', 'exists:cost_centers,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'alert_percent' => ['required', 'integer', 'between:1,100'],
            'owner_id' => ['nullable', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:60'],
            'lines.*.kind' => ['required', Rule::in(['category', 'labor'])],
            'lines.*.category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'lines.*.planned' => ['required', 'numeric', 'min:0', 'max:999999999999'],
        ], ['period_end.after_or_equal' => 'نهاية الفترة قبل بدايتها.']);
        $lines = $d['lines'];
        unset($d['lines']);

        return [$d, $lines];
    }

    /** @return array<string, mixed> */
    private static function row(Budget $b): array
    {
        return [
            ...$b->only('id', 'name', 'alert_percent'),
            'period_start' => $b->period_start->toDateString(), 'period_end' => $b->period_end->toDateString(),
            'project' => $b->project?->only('id', 'name', 'number'), 'cost_center' => $b->costCenter?->only('id', 'name'),
            'owner' => $b->owner?->only('id', 'name'), 'target' => $b->targetLabel(),
        ];
    }
}

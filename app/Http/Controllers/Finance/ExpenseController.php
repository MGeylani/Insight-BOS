<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Project;
use App\Models\User;
use App\Services\Finance\Expenses;
use App\Services\Finance\Payables;
use App\Services\Storage\Attachments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Expenses: anyone with finance.expense submits their own; approvers (finance.approve) decide;
 * finance (scope all) sees and enters everything. Record checks via Expense::visibleTo and Expenses::can*.
 */
class ExpenseController extends Controller
{
    public static function allowed(User $u): bool
    {
        return $u->hasPermission('finance.expense') || $u->hasPermission('finance.view') || $u->hasPermission('finance.approve');
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless(self::allowed($user), 403);
        $f = $request->only('search', 'status', 'category', 'project', 'cost_center', 'user', 'paid_by', 'vendor', 'from', 'to', 'mine', 'decide');
        $base = Expense::query()->visibleTo($user);

        $q = self::filtered(clone $base, $f, $user)->with(['costCenter:id,name', 'category:id,name,kind', 'user:id,name', 'vendor:id,name', 'project:id,name,number'])->withPaid();
        $month = (clone $base)->approved()->whereDate('date', '>=', now()->startOfMonth())->get(['amount', 'fx_rate']);
        $all = $user->permissionScope('finance.view') === 'all';

        return Inertia::render('finance/expenses/Index', [
            'expenses' => $q->latest('date')->latest('id')->paginate(30)->withQueryString()->through(fn (Expense $e) => self::row($e)),
            'summary' => [
                'month_usd' => round($month->sum(fn ($e) => $e->amountInBase()), 2),
                'pending' => (clone $base)->where('status', 'pending')->count(),
                'to_decide' => self::toDecide($user)->count(),
                'to_reimburse_usd' => $all ? round(Expense::approved()->where('paid_by', 'employee')->whereNull('reimbursement_id')->get()->sum(fn ($e) => $e->amountInBase()), 2) : null,
                'vendors_owed_usd' => $all ? round(array_sum(Payables::owedByVendor()), 2) : null,
            ],
            'filters' => $f,
            'categories' => ExpenseCategory::orderBy('sort')->orderBy('name')->get(['id', 'name', 'kind', 'is_active']),
            'people' => $all || $user->hasPermission('finance.approve') ? Payables::people() : [],
            'statuses' => config('finance.expense_statuses'),
            'paidBy' => config('finance.paid_by'),
            'settlements' => config('finance.settlement_labels'),
            'kinds' => config('finance.expense_category_kinds'),
            'can' => [
                'create' => $user->hasPermission('finance.expense') || Expenses::canEnterForOthers($user),
                'categories' => $user->hasPermission('settings.manage') || $user->permissionScope('finance.edit') === 'all',
                'reimburse' => $all,
            ],
        ]);
    }

    /** @param array<string, mixed> $f */
    private static function filtered(Builder $q, array $f, User $user): Builder
    {
        return $q
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('expenses.number', 'like', "%$s%")->orWhere('description', 'like', "%$s%")->orWhere('vendor_ref', 'like', "%$s%")))
            ->when($f['status'] ?? null, fn ($q, $v) => match ($v) {
                'to_reimburse' => $q->approved()->where('paid_by', 'employee')->whereNull('reimbursement_id'),
                'unpaid_bills' => $q->unpaidBills(),
                default => $q->where('status', $v),
            })
            ->when($f['category'] ?? null, fn ($q, $v) => $q->where('category_id', $v))
            ->when($f['project'] ?? null, fn ($q, $v) => $q->where('project_id', $v))
            ->when($f['cost_center'] ?? null, fn ($q, $v) => $q->where('cost_center_id', $v))
            ->when($f['user'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($f['paid_by'] ?? null, fn ($q, $v) => $q->where('paid_by', $v))
            ->when($f['vendor'] ?? null, fn ($q, $v) => $q->where('vendor_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('date', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('date', '<=', $v))
            ->when($f['mine'] ?? null, fn ($q) => $q->where('user_id', $user->id))
            ->when($f['decide'] ?? null, fn ($q) => $q->whereIn('expenses.id', self::toDecide($user)->pluck('id')));
    }

    /** Pending expenses this user may approve. */
    private static function toDecide(User $user)
    {
        if (! $user->hasPermission('finance.approve')) {
            return collect();
        }

        return Expense::with('project:id,manager_id')->where('status', 'pending')->get(['id', 'user_id', 'created_by', 'project_id', 'status'])
            ->filter(fn (Expense $e) => Expenses::canDecide($user, $e))->values();
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->hasPermission('finance.expense') || Expenses::canEnterForOthers($request->user()), 403);

        return $this->form($request, new Expense([
            'date' => today(), 'paid_by' => $request->integer('vendor_id') ? 'credit' : 'employee', 'currency' => 'USD', 'user_id' => $request->user()->id,
            'project_id' => $request->integer('project_id') ?: null, 'vendor_id' => $request->integer('vendor_id') ?: null,
        ]));
    }

    public function edit(Request $request, Expense $expense): Response
    {
        abort_unless(Expenses::canEdit($request->user(), $expense), 403);

        return $this->form($request, $expense);
    }

    private function form(Request $request, Expense $e): Response
    {
        $user = $request->user();
        $others = Expenses::canEnterForOthers($user);

        return Inertia::render('finance/expenses/Form', [
            'expense' => [
                ...$e->only('id', 'number', 'description', 'currency', 'paid_by', 'account_id', 'advance_id', 'vendor_id', 'vendor_ref', 'project_id', 'cost_center_id', 'user_id', 'category_id', 'notes'),
                'amount' => $e->amount !== null ? (float) $e->amount : '',
                'date' => $e->date?->toDateString(), 'due_date' => $e->due_date?->toDateString(),
            ],
            'categories' => ExpenseCategory::where('is_active', true)->orderBy('sort')->orderBy('name')->get(['id', 'name', 'kind']),
            'accounts' => Account::where('is_active', true)->orderBy('sort')->orderBy('name')->get(['id', 'name', 'currency']),
            'advances' => Payables::openAdvances($others ? null : $user->id),
            'vendors' => Payables::vendorOptions(),
            'projects' => Project::whereIn('status', ['planning', 'active', 'on_hold'])->orWhere('id', $e->project_id)->orderByDesc('id')->get(['id', 'number', 'name']),
            'people' => $others ? Payables::people() : [],
            'costCenters' => \App\Models\CostCenter::where('is_active', true)->orWhere('id', $e->cost_center_id)->orderBy('name')->get(['id', 'name']),
            'currencies' => Currency::where('is_active', true)->orderByDesc('is_base')->pluck('code'),
            'paidBy' => config('finance.paid_by'),
            'canVendor' => $user->hasPermission('finance.expense') || $user->hasPermission('finance.create'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('finance.expense') || Expenses::canEnterForOthers($request->user()), 403);
        $e = Expenses::save(new Expense, $this->validated($request), $request->user());

        return redirect("/finance/expenses/{$e->id}")->with('success', "سُجّل المصروف {$e->number}. أرفق صورة الإيصال ليُعتمد.");
    }

    public function update(Request $request, Expense $expense): RedirectResponse
    {
        abort_unless(Expenses::canEdit($request->user(), $expense), 403);
        Expenses::save($expense, $this->validated($request), $request->user());

        return redirect("/finance/expenses/{$expense->id}")->with('success', 'تم حفظ المصروف.');
    }

    public function show(Request $request, Expense $expense): Response
    {
        $user = $request->user();
        abort_unless(self::allowed($user) && $expense->isVisibleTo($user), 403);
        $expense->load(['costCenter:id,name', 'category:id,name,kind', 'user:id,name', 'creator:id,name', 'decider:id,name', 'vendor:id,name', 'project:id,name,number',
            'account:id,name,currency', 'advance:id,number', 'reimbursement.account:id,name', 'allocations.payment:id,number,date,currency,status']);
        $log = ActivityLog::with('user:id,name')->where('subject_type', 'expense')->where('subject_id', $expense->id)->latest('id')->limit(30)->get();

        return Inertia::render('finance/expenses/Show', [
            'expense' => [
                ...self::row($expense),
                ...$expense->only('fx_rate', 'notes', 'decision_note', 'vendor_ref'),
                'creator' => $expense->creator?->name,
                'decider' => $expense->decider?->name,
                'decided_at' => $expense->decided_at?->toIso8601String(),
                'account' => $expense->account?->only('id', 'name', 'currency'),
                'advance' => $expense->advance?->only('id', 'number'),
                'reimbursement' => $expense->reimbursement ? [
                    'date' => $expense->reimbursement->date->toDateString(), 'account' => $expense->reimbursement->account?->name, 'status' => $expense->reimbursement->status,
                ] : null,
                'payments' => $expense->allocations->map(fn ($a) => [
                    'id' => $a->id, 'amount' => (float) $a->amount, 'payment_amount' => (float) $a->payment_amount, 'payment' => [
                        ...$a->payment->only('id', 'number', 'currency', 'status'), 'date' => $a->payment->date->toDateString(),
                    ],
                ]),
                'has_receipt' => Expenses::hasReceipt($expense),
                'can' => [
                    'edit' => Expenses::canEdit($user, $expense),
                    'decide' => Expenses::canDecide($user, $expense),
                    'cancel' => Expenses::canCancel($user, $expense),
                ],
            ],
            'files' => Attachments::payload($expense, $user),
            'statuses' => config('finance.expense_statuses'),
            'paidBy' => config('finance.paid_by'),
            'settlements' => config('finance.settlement_labels'),
            'receiptRequired' => (bool) config('finance.expense_receipt_required', true),
            'log' => $log->map(fn ($l) => ['id' => $l->id, 'description' => $l->description, 'user' => $l->user?->name, 'created_at' => $l->created_at?->toIso8601String()]),
        ]);
    }

    public function approve(Request $request, Expense $expense): RedirectResponse
    {
        Expenses::approve($expense, $request->user(), $request->input('note'));

        return back()->with('success', "اعتُمد المصروف {$expense->number}.");
    }

    public function reject(Request $request, Expense $expense): RedirectResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        Expenses::reject($expense, $request->user(), $request->input('reason'));

        return back()->with('success', "رُفض المصروف {$expense->number}.");
    }

    public function cancel(Request $request, Expense $expense): RedirectResponse
    {
        abort_unless(Expenses::canCancel($request->user(), $expense), 403);
        Expenses::cancel($expense, $request->user(), (string) $request->input('reason', ''));

        return back()->with('success', "أُلغي المصروف {$expense->number}.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'date' => ['required', 'date'],
            'category_id' => ['required', Rule::exists('expense_categories', 'id')->whereNull('deleted_at')],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'currency' => ['required', 'exists:currencies,code'],
            'paid_by' => ['required', Rule::in(Expense::PAID_BY)],
            'account_id' => ['nullable', 'integer'],
            'advance_id' => ['nullable', 'integer'],
            'vendor_id' => ['nullable', Rule::exists('vendors', 'id')->whereNull('deleted_at')],
            'vendor_ref' => ['nullable', 'string', 'max:80'],
            'due_date' => ['nullable', 'date'],
            'project_id' => ['nullable', 'exists:projects,id'],
            'cost_center_id' => ['nullable', 'exists:cost_centers,id'],
            'user_id' => ['nullable', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['category_id' => 'التصنيف', 'description' => 'البيان', 'amount' => 'المبلغ']);
    }

    /** @return array<string, mixed> */
    public static function row(Expense $e): array
    {
        return [
            ...$e->only('id', 'number', 'description', 'currency', 'paid_by', 'status'),
            'date' => $e->date->toDateString(),
            'due_date' => $e->due_date?->toDateString(),
            'amount' => (float) $e->amount,
            'amount_usd' => $e->amountInBase(),
            'owed' => $e->owed(),
            'settlement' => $e->settlement(),
            'category' => $e->category?->only('id', 'name', 'kind'),
            'user' => $e->user?->only('id', 'name'),
            'vendor' => $e->vendor?->only('id', 'name'),
            'project' => $e->project?->only('id', 'name', 'number'),
            'cost_center' => $e->costCenter?->only('id', 'name'),
        ];
    }
}

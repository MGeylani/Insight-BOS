<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Advance;
use App\Models\AdvanceReturn;
use App\Models\Expense;
use App\Services\Finance\Payables;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Petty cash and salary advances. Finance (scope all) hands out and settles; employees see their own. */
class AdvanceController extends Controller
{
    private function all(Request $request, string $perm = 'finance.view'): bool
    {
        return $request->user()->permissionScope($perm) === 'all';
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless(ExpenseController::allowed($user), 403);
        $all = $this->all($request);
        $rows = Advance::with(['user:id,name', 'account:id,name'])->when(! $all, fn ($q) => $q->where('user_id', $user->id))
            ->when($request->input('user'), fn ($q, $v) => $q->where('user_id', $v))
            ->latest('date')->latest('id')->limit(200)->get();

        return Inertia::render('finance/advances/Index', [
            'advances' => $rows->map(fn (Advance $a) => self::row($a)),
            'kinds' => config('finance.advance_kinds'),
            'people' => $all ? Payables::people() : [],
            'accounts' => $this->all($request, 'finance.create') ? Account::where('is_active', true)->orderBy('sort')->orderBy('name')->get(['id', 'name', 'currency']) : [],
            'filters' => $request->only('user'),
            'can' => ['give' => $this->all($request, 'finance.create')],
        ]);
    }

    public function show(Request $request, Advance $advance): Response
    {
        $user = $request->user();
        abort_unless($this->all($request) || (int) $advance->user_id === $user->id, 403);
        $advance->load(['user:id,name', 'account:id,name', 'returns.account:id,name']);
        $expenses = Expense::with('category:id,name')->where('advance_id', $advance->id)->whereIn('status', ['pending', 'approved'])->orderBy('date')->get();

        return Inertia::render('finance/advances/Show', [
            'advance' => [...self::row($advance), 'notes' => $advance->notes],
            'expenses' => $expenses->map(fn (Expense $e) => ExpenseController::row($e)),
            'returns' => $advance->returns->map(fn (AdvanceReturn $r) => [...$r->only('id', 'notes'), 'date' => $r->date->toDateString(), 'amount' => (float) $r->amount, 'account' => $r->account?->name]),
            'accounts' => $this->all($request, 'finance.edit') ? Account::where('is_active', true)->where('currency', $advance->currency)->orderBy('name')->get(['id', 'name', 'currency']) : [],
            'kinds' => config('finance.advance_kinds'),
            'statuses' => config('finance.expense_statuses'),
            'can' => [
                'return' => $advance->isPosted() && $this->all($request, 'finance.edit'),
                'reverse' => $advance->isPosted() && $this->all($request, 'finance.delete'),
                'spend' => $advance->isPosted() && ((int) $advance->user_id === $user->id ? $user->hasPermission('finance.expense') : $this->all($request, 'finance.create')),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->all($request, 'finance.create'), 403);
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'kind' => ['required', Rule::in(array_keys(config('finance.advance_kinds')))],
            'account_id' => ['required', Rule::exists('accounts', 'id')->whereNull('deleted_at')],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $a = Payables::giveAdvance($data, $request->user()->id);

        return redirect("/finance/advances/{$a->id}")->with('success', "سُجّلت {$a->number}.");
    }

    public function returnMoney(Request $request, Advance $advance): RedirectResponse
    {
        abort_unless($this->all($request, 'finance.edit'), 403);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'account_id' => ['nullable', 'exists:accounts,id'],
            'date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        Payables::returnAdvance($advance, $data, $request->user()->id);

        return back()->with('success', 'سُجّل المرتجع.');
    }

    public function reverse(Request $request, Advance $advance): RedirectResponse
    {
        abort_unless($this->all($request, 'finance.delete'), 403);
        Payables::reverseAdvance($advance);

        return back()->with('success', "عُكست {$advance->number}.");
    }

    /** @return array<string, mixed> */
    public static function row(Advance $a): array
    {
        return [
            ...$a->only('id', 'number', 'kind', 'currency', 'status'),
            'date' => $a->date->toDateString(), 'amount' => (float) $a->amount,
            'spent' => $a->spent(), 'pending' => $a->pendingSpent(), 'returned' => $a->returned(), 'balance' => $a->balance(),
            'user' => $a->user?->only('id', 'name'), 'account' => $a->account?->name,
        ];
    }
}

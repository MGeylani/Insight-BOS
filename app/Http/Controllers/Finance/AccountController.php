<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountTransfer;
use App\Models\Currency;
use App\Services\ExchangeRates;
use App\Services\Finance\Accounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Cash boxes and bank accounts: balances, statements and transfers. Seeing them needs finance.view with scope all. */
class AccountController extends Controller
{
    public function index(Request $request): Response
    {
        $this->requireAll($request, 'finance.view');
        $accounts = Account::withTrashed()->orderBy('sort')->orderBy('name')->get();

        return Inertia::render('finance/accounts/Index', [
            'accounts' => $accounts->map(fn (Account $a) => [
                ...$a->only('id', 'name', 'type', 'currency', 'details', 'is_active', 'sort'),
                'opening_balance' => (float) $a->opening_balance,
                'opening_date' => $a->opening_date?->toDateString(),
                'archived' => $a->trashed(),
                'balance' => $balance = Accounts::balance($a),
                'balance_usd' => ($r = ExchangeRates::rateFor($a->currency)) ? round($balance / $r['rate'], 2) : null,
            ]),
            'transfers' => AccountTransfer::with(['from:id,name,currency', 'to:id,name,currency', 'creator:id,name'])->latest('date')->latest('id')->limit(20)->get()
                ->map(fn (AccountTransfer $t) => [
                    ...$t->only('id', 'reference', 'notes'), 'date' => $t->date->toDateString(), 'amount' => (float) $t->amount, 'to_amount' => (float) $t->to_amount,
                    'from' => $t->from?->only('id', 'name', 'currency'), 'to' => $t->to?->only('id', 'name', 'currency'),
                    'reversed' => $t->reversed_at !== null, 'by' => $t->creator?->name,
                ]),
            'types' => config('finance.account_types'),
            'currencies' => Currency::where('is_active', true)->orderByDesc('is_base')->pluck('code'),
            'canManage' => $request->user()->permissionScope('finance.edit') === 'all',
        ]);
    }

    public function show(Request $request, Account $account): Response
    {
        $this->requireAll($request, 'finance.view');
        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? today();

        return Inertia::render('finance/accounts/Show', [
            'account' => [...$account->only('id', 'name', 'type', 'currency', 'details'), 'balance' => Accounts::balance($account)],
            'statement' => Accounts::statement($account, $from->toDateString(), $to->toDateString()),
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'types' => config('finance.account_types'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireAll($request, 'finance.edit');
        Accounts::save(new Account, $this->validated($request), $request->user()->id);

        return back()->with('success', 'تمت إضافة الحساب.');
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        $this->requireAll($request, 'finance.edit');
        Accounts::save($account, $this->validated($request), $request->user()->id);

        return back()->with('success', 'تم حفظ الحساب.');
    }

    /** Archive (soft delete): its history stays; it disappears from pickers. */
    public function destroy(Request $request, Account $account): RedirectResponse
    {
        $this->requireAll($request, 'finance.edit');
        $account->delete();

        return back()->with('success', 'أُرشف الحساب. تبقى حركاته في السجلات والتقارير.');
    }

    public function restore(Request $request, int $account): RedirectResponse
    {
        $this->requireAll($request, 'finance.edit');
        Account::withTrashed()->findOrFail($account)->restore();

        return back()->with('success', 'أُعيد الحساب.');
    }

    public function transfer(Request $request): RedirectResponse
    {
        $this->requireAll($request, 'finance.edit');
        $data = $request->validate([
            'from_account_id' => ['required', Rule::exists('accounts', 'id')->whereNull('deleted_at')],
            'to_account_id' => ['required', Rule::exists('accounts', 'id')->whereNull('deleted_at')],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'to_amount' => ['nullable', 'numeric', 'gt:0', 'max:999999999999'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        Accounts::transfer($data, $request->user()->id);

        return back()->with('success', 'تم تسجيل التحويل.');
    }

    public function reverseTransfer(Request $request, AccountTransfer $transfer): RedirectResponse
    {
        $this->requireAll($request, 'finance.delete');
        Accounts::reverseTransfer($transfer, $request->user()->id);

        return back()->with('success', 'عُكس التحويل.');
    }

    private function requireAll(Request $request, string $permission): void
    {
        abort_unless($request->user()->permissionScope($permission) === 'all', 403, 'الحسابات تحتاج صلاحية المالية بنطاق «الكل».');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(array_keys(config('finance.account_types')))],
            'currency' => ['required', 'exists:currencies,code'],
            'opening_balance' => ['nullable', 'numeric', 'between:-999999999999,999999999999'],
            'opening_date' => ['nullable', 'date'],
            'details' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
            'sort' => ['nullable', 'integer', 'min:0'],
        ]) + ['opening_balance' => 0];
    }
}

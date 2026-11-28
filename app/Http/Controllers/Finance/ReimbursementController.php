<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Reimbursement;
use App\Services\Finance\Payables;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Paying employees back for approved out-of-pocket expenses. Finance with scope all. */
class ReimbursementController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->permissionScope('finance.view') === 'all', 403);

        return Inertia::render('finance/reimbursements/Index', [
            'groups' => Payables::toReimburse(),
            'recent' => Reimbursement::with(['user:id,name', 'account:id,name'])->withCount('expenses')->latest('date')->latest('id')->limit(30)->get()
                ->map(fn (Reimbursement $r) => [
                    ...$r->only('id', 'currency', 'reference', 'status', 'expenses_count'), 'date' => $r->date->toDateString(), 'amount' => (float) $r->amount,
                    'user' => $r->user?->name, 'account' => $r->account?->name,
                ]),
            'accounts' => Account::where('is_active', true)->orderBy('sort')->orderBy('name')->get(['id', 'name', 'currency']),
            'can' => [
                'pay' => $request->user()->permissionScope('finance.create') === 'all',
                'reverse' => $request->user()->permissionScope('finance.delete') === 'all',
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->permissionScope('finance.create') === 'all', 403);
        $d = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'account_id' => ['required', 'exists:accounts,id'],
            'date' => ['required', 'date'],
            'expense_ids' => ['required', 'array', 'min:1'],
            'expense_ids.*' => ['integer'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);
        $r = Payables::reimburse((int) $d['user_id'], (int) $d['account_id'], $d['date'], array_map('intval', $d['expense_ids']), $d['reference'] ?? null, $request->user()->id);

        return back()->with('success', "سُجّل تعويض {$r->amount} {$r->currency}.");
    }

    public function reverse(Request $request, Reimbursement $reimbursement): RedirectResponse
    {
        abort_unless($request->user()->permissionScope('finance.delete') === 'all', 403);
        Payables::reverseReimbursement($reimbursement);

        return back()->with('success', 'عُكس التعويض وعادت المصروفات بانتظار التعويض.');
    }
}

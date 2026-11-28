<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Expense;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Services\ActivityLogger;
use App\Services\Finance\Payables;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Vendors, their bills (credit expenses) and payments to them. Viewing needs finance.view; paying needs scope all. */
class VendorController extends Controller
{
    public function index(Request $request): Response
    {
        $owed = Payables::owedByVendor();
        $vendors = Vendor::withCount(['expenses as bills_count' => fn ($q) => $q->where('paid_by', 'credit')->where('status', 'approved')])
            ->when($request->input('search'), fn ($q, $s) => $q->where('name', 'like', "%$s%"))
            ->orderBy('name')->get();

        return Inertia::render('finance/vendors/Index', [
            'vendors' => $vendors->map(fn (Vendor $v) => [...$v->only('id', 'name', 'type', 'phone', 'email', 'tax_number', 'payment_details', 'notes'), 'bills_count' => $v->bills_count, 'owed_usd' => $owed[$v->id] ?? 0.0]),
            'total_owed_usd' => round(array_sum($owed), 2),
            'types' => config('finance.vendor_types'),
            'search' => $request->input('search'),
            'can' => ['edit' => $request->user()->hasPermission('finance.edit')],
        ]);
    }

    public function show(Request $request, Vendor $vendor): Response
    {
        $user = $request->user();
        $bills = Expense::with(['category:id,name', 'project:id,name,number'])->withPaid()->where('vendor_id', $vendor->id)
            ->whereIn('status', ['pending', 'approved'])->latest('date')->limit(100)->get();
        $payments = VendorPayment::with('account:id,name')->where('vendor_id', $vendor->id)->latest('date')->latest('id')->limit(50)->get();
        $all = $user->permissionScope('finance.create') === 'all';

        return Inertia::render('finance/vendors/Show', [
            'vendor' => [...$vendor->only('id', 'name', 'type', 'phone', 'email', 'tax_number', 'payment_details', 'notes'), 'owed_usd' => Payables::owedByVendor()[$vendor->id] ?? 0.0],
            'bills' => $bills->map(fn (Expense $e) => ExpenseController::row($e) + ['vendor_ref' => $e->vendor_ref]),
            'openBills' => Payables::openBills($vendor->id),
            'payments' => $payments->map(fn (VendorPayment $p) => [
                ...$p->only('id', 'number', 'currency', 'method', 'reference', 'status', 'reverse_reason'), 'date' => $p->date->toDateString(),
                'amount' => (float) $p->amount, 'unallocated' => $p->unallocated(), 'account' => $p->account?->name,
            ]),
            'accounts' => $all ? Account::where('is_active', true)->orderBy('sort')->orderBy('name')->get(['id', 'name', 'currency']) : [],
            'methods' => config('finance.payment_methods'),
            'types' => config('finance.vendor_types'),
            'settlements' => config('finance.settlement_labels'),
            'statuses' => config('finance.expense_statuses'),
            'can' => [
                'pay' => $all,
                'reverse' => $user->permissionScope('finance.delete') === 'all',
                'edit' => $user->hasPermission('finance.edit'),
                'bill' => $user->hasPermission('finance.expense') || $user->hasPermission('finance.create'),
            ],
        ]);
    }

    /** Also used from the expense form (JSON) by anyone who submits expenses. */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $u = $request->user();
        abort_unless($u->hasPermission('finance.expense') || $u->hasPermission('finance.create'), 403);
        $v = Vendor::create($this->validated($request) + ['created_by' => $u->id]);
        ActivityLogger::log('finance.vendor_created', $v, "إضافة المورد {$v->name}");

        return $request->wantsJson() ? response()->json(['vendor' => $v->only('id', 'name', 'type')]) : back()->with('success', 'تمت إضافة المورد.');
    }

    public function update(Request $request, Vendor $vendor): RedirectResponse
    {
        $vendor->update($this->validated($request));
        ActivityLogger::log('finance.vendor_updated', $vendor, "تعديل المورد {$vendor->name}");

        return back()->with('success', 'تم حفظ المورد.');
    }

    public function destroy(Vendor $vendor): RedirectResponse
    {
        $vendor->delete();
        ActivityLogger::log('finance.vendor_archived', $vendor, "أرشفة المورد {$vendor->name}");

        return redirect('/finance/vendors')->with('success', 'أُرشف المورد. تبقى فواتيره ودفعاته في السجلات.');
    }

    public function pay(Request $request, Vendor $vendor): RedirectResponse
    {
        abort_unless($request->user()->permissionScope('finance.create') === 'all', 403, 'الدفع للموردين يحتاج صلاحية المالية بنطاق «الكل».');
        $data = $request->validate([
            'account_id' => ['required', Rule::exists('accounts', 'id')->whereNull('deleted_at')],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'method' => ['required', Rule::in(array_keys(config('finance.payment_methods')))],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'allocations' => ['array', 'max:100'],
            'allocations.*.expense_id' => ['required', 'integer'],
            'allocations.*.amount' => ['nullable', 'numeric', 'min:0'],
            'allocations.*.payment_amount' => ['nullable', 'numeric', 'min:0'],
        ]);
        $alloc = $data['allocations'] ?? [];
        unset($data['allocations']);
        $p = Payables::payVendor($data + ['vendor_id' => $vendor->id], $alloc, $request->user()->id);

        return back()->with('success', "سُجّلت الدفعة {$p->number}.");
    }

    public function allocate(Request $request, VendorPayment $payment): RedirectResponse
    {
        abort_unless($request->user()->permissionScope('finance.edit') === 'all', 403);
        $data = $request->validate(['expense_id' => ['required', 'exists:expenses,id'], 'amount' => ['required', 'numeric', 'gt:0'], 'payment_amount' => ['nullable', 'numeric', 'gt:0']]);
        Payables::allocateBill($payment, Expense::findOrFail($data['expense_id']), (float) $data['amount'], isset($data['payment_amount']) ? (float) $data['payment_amount'] : null);

        return back()->with('success', 'سُوّيت الفاتورة من رصيد الدفعة.');
    }

    public function reversePayment(Request $request, VendorPayment $payment): RedirectResponse
    {
        abort_unless($request->user()->permissionScope('finance.delete') === 'all', 403);
        $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        Payables::reverseVendorPayment($payment, $request->input('reason'), $request->user()->id);

        return back()->with('success', "عُكست الدفعة {$payment->number}.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in(array_keys(config('finance.vendor_types')))],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'tax_number' => ['nullable', 'string', 'max:60'],
            'payment_details' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}

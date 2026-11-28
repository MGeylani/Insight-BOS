<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\Finance\Payments;
use App\Services\Finance\ReceiptPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $f = $request->only('search', 'kind', 'account', 'client', 'from', 'to', 'status');
        $q = Payment::visibleTo($user, 'finance.view')->with(['client:id,name', 'account:id,name,currency'])
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%$s%")->orWhere('reference', 'like', "%$s%")
                ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%$s%"))))
            ->when($f['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['account'] ?? null, fn ($q, $v) => $q->where('account_id', $v))
            ->when($f['client'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('date', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('date', '<=', $v));

        $month = Payment::visibleTo($user, 'finance.view')->posted()->whereDate('date', '>=', now()->startOfMonth())->get();

        return Inertia::render('finance/payments/Index', [
            'payments' => (clone $q)->latest('date')->latest('id')->paginate(30)->withQueryString()->through(fn (Payment $p) => self::row($p)),
            'summary' => [
                'received_month_usd' => round($month->where('kind', 'receipt')->sum(fn ($p) => $p->amountInBase()), 2),
                'refunded_month_usd' => round($month->where('kind', 'refund')->sum(fn ($p) => $p->amountInBase()), 2),
            ],
            'filters' => $f,
            'accounts' => Account::orderBy('sort')->orderBy('name')->get(['id', 'name', 'currency']),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'methods' => config('finance.payment_methods'),
            'can' => ['create' => $user->hasPermission('finance.create')],
        ]);
    }

    public function create(Request $request): Response
    {
        $clientId = $request->integer('client_id') ?: null;
        if ($inv = $request->integer('invoice_id') ? Invoice::find($request->integer('invoice_id')) : null) {
            $this->authorizeRecord($inv, 'finance.view');
            $clientId = $inv->client_id;
        }

        return Inertia::render('finance/payments/Create', [
            'clients' => Client::orderBy('name')->get(['id', 'name', 'currency']),
            'accounts' => Account::where('is_active', true)->orderBy('sort')->orderBy('name')->get(['id', 'name', 'currency', 'type']),
            'methods' => config('finance.payment_methods'),
            'preset' => ['client_id' => $clientId, 'invoice_id' => $inv?->id],
        ]);
    }

    /** Open invoices and credit of a client (JSON for the receipt form). */
    public function clientOpen(Client $client): JsonResponse
    {
        return response()->json(['invoices' => Payments::openInvoices($client->id), 'credits' => Payments::credits($client)]);
    }

    /** Amount converted between currencies at a date's rates (JSON for the receipt form). */
    public function convert(Request $request): JsonResponse
    {
        $d = $request->validate(['amount' => ['required', 'numeric'], 'from' => ['required', 'exists:currencies,code'], 'to' => ['required', 'exists:currencies,code'], 'date' => ['nullable', 'date']]);

        return response()->json(['amount' => Payments::convert((float) $d['amount'], $d['from'], $d['to'], $d['date'] ?? null)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'account_id' => ['required', Rule::exists('accounts', 'id')->whereNull('deleted_at')],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'method' => ['required', Rule::in(array_keys(config('finance.payment_methods')))],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'allocations' => ['array', 'max:100'],
            'allocations.*.invoice_id' => ['required', 'integer'],
            'allocations.*.amount' => ['nullable', 'numeric', 'min:0'],
            'allocations.*.payment_amount' => ['nullable', 'numeric', 'min:0'],
        ]);
        $allocations = $data['allocations'] ?? [];
        unset($data['allocations']);
        foreach ($allocations as $a) {
            $inv = Invoice::find($a['invoice_id']);
            abort_unless($inv && $inv->isVisibleTo($request->user(), 'finance.view'), 403);
        }
        $p = Payments::receive($data, $allocations, $request->user()->id);
        $redirect = $request->input('back') === 'invoice' && count($allocations) === 1 ? '/finance/invoices/'.$allocations[0]['invoice_id'] : "/finance/payments/{$p->id}";

        return redirect($redirect)->with('success', "تم تسجيل سند القبض {$p->number}.");
    }

    public function show(Request $request, Payment $payment): Response
    {
        $this->authorizeRecord($payment, 'finance.view');
        $payment->load(['client:id,name', 'account:id,name,currency,type', 'creator:id,name', 'reverser:id,name', 'refundOf:id,number',
            'allocations.invoice:id,number,title,currency', 'refunds.account:id,name']);
        $user = $request->user();
        $log = ActivityLog::with('user:id,name')->where('subject_type', 'payment')->where('subject_id', $payment->id)->latest('id')->limit(30)->get();

        return Inertia::render('finance/payments/Show', [
            'payment' => [
                ...self::row($payment),
                ...$payment->only('fx_rate', 'notes', 'reverse_reason'),
                'reversed_at' => $payment->reversed_at?->toIso8601String(),
                'reverser' => $payment->reverser?->name,
                'creator' => $payment->creator?->name,
                'refund_of' => $payment->refundOf?->only('id', 'number'),
                'unallocated' => $payment->unallocated(),
                'allocations' => $payment->allocations->map(fn (PaymentAllocation $a) => [
                    'id' => $a->id, 'amount' => (float) $a->amount, 'payment_amount' => (float) $a->payment_amount,
                    'invoice' => $a->invoice?->only('id', 'number', 'title', 'currency'),
                ]),
                'refunds' => $payment->refunds->map(fn (Payment $r) => [...self::row($r)]),
                'can_edit' => $payment->isPosted() && $payment->isVisibleTo($user, 'finance.edit'),
                'can_reverse' => $payment->isPosted() && $payment->isVisibleTo($user, 'finance.delete'),
            ],
            'openInvoices' => $payment->isReceipt() && $payment->isPosted() ? Payments::openInvoices($payment->client_id) : [],
            'accounts' => Account::where('is_active', true)->where('currency', $payment->currency)->orderBy('name')->get(['id', 'name', 'currency']),
            'methods' => config('finance.payment_methods'),
            'log' => $log->map(fn ($l) => ['id' => $l->id, 'description' => $l->description, 'user' => $l->user?->name, 'created_at' => $l->created_at?->toIso8601String()]),
        ]);
    }

    public function allocate(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorizeRecord($payment, 'finance.edit');
        $data = $request->validate([
            'invoice_id' => ['required', 'exists:invoices,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_amount' => ['nullable', 'numeric', 'gt:0'],
        ]);
        $invoice = Invoice::findOrFail($data['invoice_id']);
        $this->authorizeRecord($invoice, 'finance.view');
        Payments::allocate($payment, $invoice, (float) $data['amount'], isset($data['payment_amount']) ? (float) $data['payment_amount'] : null, $request->user()->id);

        return back()->with('success', "سُوّيت الفاتورة {$invoice->number}.");
    }

    public function deallocate(Request $request, PaymentAllocation $allocation): RedirectResponse
    {
        $this->authorizeRecord($allocation->payment, 'finance.edit');
        abort_unless($allocation->payment->isPosted(), 422);
        Payments::deallocate($allocation);

        return back()->with('success', 'فُكّت التسوية، وصار المبلغ رصيداً للعميل.');
    }

    public function refund(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorizeRecord($payment, 'finance.edit');
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'account_id' => ['required', 'exists:accounts,id'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', Rule::in(array_keys(config('finance.payment_methods')))],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $r = Payments::refund($payment, $data, $request->user()->id);

        return back()->with('success', "سُجّل ردّ المبلغ ({$r->number}).");
    }

    public function reverse(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorizeRecord($payment, 'finance.delete');
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        Payments::reverse($payment, $data['reason'], $request->user()->id);

        return back()->with('success', "عُكس السند {$payment->number}؛ أُعيد فتح الفواتير التي سوّاها.");
    }

    public function pdf(Request $request, Payment $payment): HttpResponse
    {
        $this->authorizeRecord($payment, 'finance.view');
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response(ReceiptPdf::render($payment), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "$disposition; filename=\"{$payment->number}.pdf\"",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return array<string, mixed> */
    public static function row(Payment $p): array
    {
        return [
            ...$p->only('id', 'number', 'kind', 'currency', 'method', 'reference', 'status'),
            'date' => $p->date->toDateString(),
            'amount' => (float) $p->amount,
            'amount_usd' => $p->amountInBase(),
            'client' => $p->client?->only('id', 'name'),
            'account' => $p->account?->only('id', 'name', 'currency'),
        ];
    }
}

<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Project;
use App\Models\Quote;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\ExchangeRates;
use App\Services\Finance\InvoicePdf;
use App\Services\Finance\Invoices;
use App\Services\Storage\Attachments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $f = $request->only('search', 'status', 'client', 'project');

        $invoices = self::filtered(Invoice::visibleTo($user, 'finance.view'), $f)
            ->with(['client:id,name', 'owner:id,name', 'items'])->withPaid()
            ->latest('id')->paginate(30)->withQueryString();

        $open = Invoice::visibleTo($user, 'finance.view')->withPaid()->unpaid()->get();

        return Inertia::render('finance/invoices/Index', [
            'invoices' => $invoices->through(fn (Invoice $i) => self::row($i)),
            'summary' => [
                'drafts' => Invoice::visibleTo($user, 'finance.view')->where('status', 'draft')->count(),
                'open_count' => $open->filter(fn (Invoice $i) => $i->balance() > 0)->count(),
                'open_usd' => round($open->sum(fn (Invoice $i) => $i->toBase($i->balance()) ?? 0), 2),
                'overdue_count' => $open->filter->isOverdue()->count(),
                'overdue_usd' => round($open->filter->isOverdue()->sum(fn (Invoice $i) => $i->toBase($i->balance()) ?? 0), 2),
                'issued_month_usd' => round(Invoice::visibleTo($user, 'finance.view')->where('status', 'issued')
                    ->whereDate('issue_date', '>=', now()->startOfMonth())->get()->sum(fn (Invoice $i) => $i->totalInBase() ?? 0), 2),
            ],
            'filters' => $f,
            'statuses' => config('finance.invoice_statuses'),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'can' => ['create' => $user->hasPermission('finance.create')],
        ]);
    }

    /** Status filter on derived statuses (paid / partial / overdue need the payment sums). */
    public static function filtered(Builder $q, array $f): Builder
    {
        return $q
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('title', 'like', "%$s%")->orWhere('number', 'like', "%$s%")
                ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%$s%"))))
            ->when($f['client'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
            ->when($f['project'] ?? null, fn ($q, $v) => $q->where('project_id', $v))
            ->when($f['status'] ?? null, function ($q, $st) {
                match ($st) {
                    'draft', 'cancelled' => $q->where('status', $st),
                    'paid' => $q->paid(),
                    'partial' => $q->unpaid()->has('allocations'),
                    'overdue' => $q->unpaid()->whereDate('due_date', '<', today()),
                    default => $q->unpaid(), // «غير مدفوعة»: everything still owing
                };
            });
    }

    public function create(Request $request): Response
    {
        $user = $request->user();
        if ($id = $request->integer('quote_id')) {
            $quote = Quote::with('items')->findOrFail($id);
            abort_unless($quote->isVisibleTo($user, 'crm.view'), 403);
            $form = Invoices::fromQuote($quote);
        } elseif ($id = $request->integer('contract_id')) {
            $contract = Contract::findOrFail($id);
            abort_unless($contract->isVisibleTo($user, 'crm.view'), 403);
            $ids = array_map('intval', (array) $request->input('schedule', []));
            $form = Invoices::fromSchedules($contract, $ids ?: $contract->schedules->filter(fn ($s) => Invoices::schedulePending($s))->take(1)->pluck('id')->all());
        } else {
            $client = $request->integer('client_id') ? Client::find($request->integer('client_id')) : null;
            $form = Invoices::blank($client?->id, $client?->currency ?? 'USD');
            if ($pid = $request->integer('project_id')) {
                $p = Project::findOrFail($pid);
                $form['client_id'] = $p->client_id;
                $form['project_id'] = $p->id;
                $form['title'] = $p->name;
                $form['currency'] = $p->client?->currency ?? $form['currency'];
            }
        }

        return $this->editor($request, null, $form);
    }

    public function store(Request $request): RedirectResponse
    {
        [$data, $items] = $this->validated($request);
        $this->checkLinks($data);
        $invoice = Invoices::saveDraft(new Invoice(['created_by' => $request->user()->id]),
            $data + ['owner_id' => $data['owner_id'] ?? $request->user()->id], $items);
        ActivityLogger::log('finance.invoice_created', $invoice, "إنشاء مسودة فاتورة: {$invoice->title}");

        return redirect("/finance/invoices/{$invoice->id}")->with('success', 'تم حفظ مسودة الفاتورة.');
    }

    public function show(Request $request, Invoice $invoice): Response
    {
        $this->authorizeRecord($invoice, 'finance.view');
        $invoice->load(['client:id,name', 'contact:id,name,phone,email', 'project:id,name,number', 'contract:id,number,title', 'quote:id,number,title',
            'owner:id,name', 'creator:id,name', 'sourceInvoice:id,number', 'items.schedule:id,title', 'items.timeEntries:id,invoice_item_id,minutes']);
        $user = $request->user();
        $log = ActivityLog::with('user:id,name')->where('subject_type', 'invoice')->where('subject_id', $invoice->id)->latest('id')->limit(50)->get();

        return Inertia::render('finance/invoices/Show', [
            'invoice' => [
                ...self::row($invoice),
                ...$invoice->only('client_id', 'intro', 'terms', 'notes', 'payment_details', 'fx_rate', 'cancel_reason'),
                'contact' => $invoice->contact?->only('id', 'name', 'phone', 'email'),
                'project' => $invoice->project?->only('id', 'name', 'number'),
                'contract' => $invoice->contract?->only('id', 'number', 'title'),
                'quote' => $invoice->quote?->only('id', 'number', 'title'),
                'creator' => $invoice->creator?->only('id', 'name'),
                'source' => $invoice->sourceInvoice?->only('id', 'number'),
                'issued_at' => $invoice->issued_at?->toIso8601String(),
                'cancelled_at' => $invoice->cancelled_at?->toIso8601String(),
                'created_at' => $invoice->created_at->toIso8601String(),
                'items' => $invoice->items->map(fn (InvoiceItem $it) => self::itemPayload($it)),
                'totals' => $invoice->isDraft() ? $invoice->totals() : [...$invoice->totals(), 'total' => $invoice->grandTotal()],
                'paid' => $invoice->paidAmount(),
                'balance' => $invoice->balance(),
                'can_edit' => $invoice->isVisibleTo($user, 'finance.edit'),
                'can_cancel' => $invoice->isIssued() && $invoice->isVisibleTo($user, 'finance.delete'),
                'can_delete' => $invoice->isDraft() && $invoice->isVisibleTo($user, 'finance.delete'),
                'missing_rate' => $invoice->isDraft() && ExchangeRates::rateFor($invoice->currency) === null,
            ],
            'files' => Attachments::payload($invoice, $user),
            'payments' => $invoice->allocations()->with('payment.account:id,name')->get()->map(fn ($a) => [
                'id' => $a->id, 'amount' => (float) $a->amount, 'payment_amount' => (float) $a->payment_amount,
                'payment' => [...$a->payment->only('id', 'number', 'currency', 'method'), 'date' => $a->payment->date->toDateString(), 'account' => $a->payment->account?->name],
            ]),
            'credits' => $invoice->isIssued() && $invoice->balance() > 0 ? \App\Services\Finance\Payments::credits($invoice->client) : [],
            'accounts' => \App\Models\Account::where('is_active', true)->orderBy('sort')->orderBy('name')->get(['id', 'name', 'currency']),
            'methods' => config('finance.payment_methods'),
            'log' => $log->map(fn ($l) => [
                'id' => $l->id, 'description' => $l->description, 'user' => $l->user?->name, 'created_at' => $l->created_at?->toIso8601String(),
            ]),
            'statuses' => config('finance.invoice_statuses'),
        ]);
    }

    public function edit(Request $request, Invoice $invoice): Response|RedirectResponse
    {
        $this->authorizeRecord($invoice, 'finance.edit');
        if (! $invoice->isDraft()) {
            return redirect("/finance/invoices/{$invoice->id}")->with('error', 'الفاتورة صادرة ولا تُعدّل.');
        }
        $invoice->load('items.timeEntries:id,invoice_item_id');

        return $this->editor($request, $invoice, [
            ...$invoice->only('client_id', 'contact_id', 'project_id', 'contract_id', 'quote_id', 'title', 'currency', 'owner_id'),
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'intro' => $invoice->intro ?? '', 'terms' => $invoice->terms ?? '', 'notes' => $invoice->notes ?? '', 'payment_details' => $invoice->payment_details ?? '',
            'items' => $invoice->items->map(fn (InvoiceItem $it) => [
                ...$it->only('service_id', 'service_category_id', 'contract_schedule_id', 'name', 'details', 'unit'),
                'quantity' => (float) $it->quantity, 'unit_price' => (float) $it->unit_price, 'discount_percent' => (float) $it->discount_percent,
                'time_ids' => $it->timeEntries->pluck('id')->all(),
            ])->all(),
        ]);
    }

    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorizeRecord($invoice, 'finance.edit');
        [$data, $items] = $this->validated($request);
        $data['client_id'] = $invoice->client_id; // an invoice never moves to another client
        $this->checkLinks($data);
        Invoices::saveDraft($invoice, $data, $items);
        ActivityLogger::log('finance.invoice_updated', $invoice, "تعديل مسودة الفاتورة: {$invoice->title}");

        return redirect("/finance/invoices/{$invoice->id}")->with('success', 'تم حفظ المسودة.');
    }

    public function issue(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorizeRecord($invoice, 'finance.edit');
        Invoices::issue($invoice, $request->user());

        return back()->with('success', "تم إصدار الفاتورة برقم {$invoice->number}. نزّل ملف PDF وأرسله للعميل.");
    }

    public function cancel(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorizeRecord($invoice, 'finance.delete');
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        Invoices::cancel($invoice, $data['reason']);

        return back()->with('success', 'تم إلغاء الفاتورة. يبقى رقمها محفوظاً، وعادت دفعات العقد والساعات قابلة للفوترة.');
    }

    public function duplicate(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorizeRecord($invoice, 'finance.view');
        abort_unless($request->user()->hasPermission('finance.create'), 403);
        $copy = Invoices::duplicate($invoice, $request->user()->id);

        return redirect("/finance/invoices/{$copy->id}/edit")->with('success', 'أُنشئت مسودة جديدة من الفاتورة.');
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        $this->authorizeRecord($invoice, 'finance.delete');
        Invoices::deleteDraft($invoice);

        return redirect('/finance/invoices')->with('success', 'تم حذف المسودة.');
    }

    public function pdf(Request $request, Invoice $invoice): HttpResponse
    {
        $this->authorizeRecord($invoice, 'finance.view');
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response(InvoicePdf::render($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "$disposition; filename=\"".InvoicePdf::filename($invoice).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Approved billable time of a project not yet invoiced (JSON for the editor). */
    public function billableTime(Request $request, Project $project): JsonResponse
    {
        abort_unless($request->user()->hasPermission('finance.create') || $request->user()->hasPermission('finance.edit'), 403);
        $except = $request->integer('invoice') ? Invoice::find($request->integer('invoice')) : null;

        return response()->json(['groups' => Invoices::billableTime($project, $except)]);
    }

    /** Pending, not yet invoiced instalments of a client's contracts (JSON for the editor). */
    public function pendingSchedules(Request $request, Client $client): JsonResponse
    {
        abort_unless($request->user()->hasPermission('finance.create') || $request->user()->hasPermission('finance.edit'), 403);
        $contracts = Contract::with('schedules')->where('client_id', $client->id)->whereIn('status', ['sent', 'active', 'completed'])->get();
        $own = $request->integer('invoice');

        return response()->json(['rows' => $contracts->flatMap(fn (Contract $c) => $c->schedules
            ->filter(fn ($s) => $s->kind === 'payment' && $s->status === 'pending' && ($s->invoice_id === null || $s->invoice_id === $own) && (float) $s->amount > 0)
            ->map(fn ($s) => [...Invoices::scheduleLine($c, $s), 'contract' => $c->number ?? '#'.$c->id, 'currency' => $c->currency, 'due_date' => $s->due_date?->toDateString()]))->values()]);
    }

    // ── helpers ──────────────────────────────────────────────────────

    /** @param array<string, mixed> $form */
    private function editor(Request $request, ?Invoice $invoice, array $form): Response
    {
        $clients = Client::orderBy('name')->limit(2000)
            ->with(['contacts:id,client_id,name', 'projects' => fn ($q) => $q->select('id', 'client_id', 'name', 'number')])
            ->get(['id', 'name', 'currency']);

        return Inertia::render('finance/invoices/Edit', [
            'invoice' => $invoice?->only('id', 'title'),
            'form' => $form,
            'clients' => $clients->map(fn (Client $c) => [
                ...$c->only('id', 'name', 'currency'),
                'contacts' => $c->contacts->map->only('id', 'name')->values(),
                'projects' => $c->projects->map->only('id', 'name', 'number')->values(),
            ]),
            'services' => Service::active()->orderBy('sort')->orderBy('name')
                ->get(['id', 'service_category_id', 'name', 'description', 'unit', 'price', 'currency']),
            'categories' => ServiceCategory::where('is_active', true)->orderBy('sort')->get(['id', 'name', 'tax_rate']),
            'units' => config('crm.service_units'),
            'users' => User::where('is_active', true)->where('is_guest', false)->orderBy('name')->get(['id', 'name']),
            'currencies' => Currency::where('is_active', true)->orderByDesc('is_base')->pluck('code'),
            'paymentDays' => Invoices::paymentDays(),
        ]);
    }

    /** Linked project / contract / quote must belong to the invoice's client. */
    private function checkLinks(array $data): void
    {
        $errors = [];
        foreach (['project_id' => Project::class, 'contract_id' => Contract::class, 'quote_id' => Quote::class] as $k => $class) {
            if (! empty($data[$k]) && (int) $class::withTrashed()->whereKey($data[$k])->value('client_id') !== (int) $data['client_id']) {
                $errors[$k] = 'السجل المرتبط لا يتبع هذا العميل.';
            }
        }
        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
    }

    /** @return array<string, mixed> */
    public static function row(Invoice $i): array
    {
        $total = $i->grandTotal();

        return [
            ...$i->only('id', 'number', 'title', 'currency'),
            'status' => $i->displayStatus(),
            'issue_date' => $i->issue_date?->toDateString(),
            'due_date' => $i->due_date?->toDateString(),
            'client' => $i->client?->only('id', 'name'),
            'owner' => $i->owner?->only('id', 'name'),
            'total' => $total,
            'total_usd' => $i->toBase($total),
            'balance' => $i->balance(),
        ];
    }

    /** @return array<string, mixed> */
    private static function itemPayload(InvoiceItem $it): array
    {
        return [
            ...$it->only('id', 'name', 'details', 'unit', 'contract_schedule_id'),
            'quantity' => (float) $it->quantity,
            'unit_price' => (float) $it->unit_price,
            'discount_percent' => (float) $it->discount_percent,
            'tax_rate' => (float) $it->tax_rate,
            'net' => $it->net(),
            'tax' => $it->taxAmount(),
            'schedule' => $it->schedule?->only('id', 'title'),
            'minutes' => (int) $it->timeEntries->sum('minutes'),
        ];
    }

    /** @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'contact_id' => ['nullable', Rule::exists('contacts', 'id')->where('client_id', $request->input('client_id'))],
            'project_id' => ['nullable', 'exists:projects,id'],
            'contract_id' => ['nullable', 'exists:contracts,id'],
            'quote_id' => ['nullable', 'exists:quotes,id'],
            'title' => ['required', 'string', 'max:190'],
            'currency' => ['required', 'exists:currencies,code'],
            'issue_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'intro' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'payment_details' => ['nullable', 'string', 'max:5000'],
            'owner_id' => ['nullable', 'exists:users,id'],
            'items' => ['array', 'max:200'],
            'items.*.service_id' => ['nullable', 'exists:services,id'],
            'items.*.service_category_id' => ['nullable', 'exists:service_categories,id'],
            'items.*.contract_schedule_id' => ['nullable', 'integer'],
            'items.*.time_ids' => ['nullable', 'array'],
            'items.*.time_ids.*' => ['integer'],
            'items.*.name' => ['required', 'string', 'max:190'],
            'items.*.details' => ['nullable', 'string', 'max:3000'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'items.*.unit' => ['nullable', 'string', 'max:32'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'between:0,100'],
        ], [
            'items.*.name.required' => 'اكتب اسم البند.',
            'items.*.quantity.gt' => 'الكمية يجب أن تكون أكبر من صفر.',
            'due_date.after_or_equal' => 'تاريخ الاستحقاق قبل تاريخ الإصدار.',
        ]);
        $items = $data['items'] ?? [];
        unset($data['items']);

        return [$data, array_values($items)];
    }
}

<?php

namespace App\Http\Controllers\Crm;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractSchedule;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Services\ActivityLogger;
use App\Services\ExchangeRates;
use App\Services\Sales\ContractPdf;
use App\Services\Sales\Contracts;
use App\Services\Sales\Quotes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ContractController extends CrmController
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $f = $request->only('search', 'status', 'type', 'owner', 'client');

        $contracts = Contract::visibleTo($user)->with(['client:id,name', 'owner:id,name', 'schedules'])
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('title', 'like', "%$s%")->orWhere('number', 'like', "%$s%")
                ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%$s%"))))
            ->when($f['status'] ?? null, function ($q, $st) {
                match ($st) {
                    'expired' => $q->where('status', 'active')->whereDate('end_date', '<', today()),
                    'active' => $q->where('status', 'active')->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', today())),
                    default => $q->where('status', $st),
                };
            })
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['owner'] ?? null, fn ($q, $v) => $q->where('owner_id', $v))
            ->when($f['client'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
            ->latest('id')->paginate(30)->withQueryString();

        $active = Contract::visibleTo($user)->with('schedules')->where('status', 'active')->get();
        $soon = today()->addDays(30);
        $due = $active->flatMap(fn (Contract $c) => $c->schedules
            ->filter(fn (ContractSchedule $s) => $s->kind === 'payment' && $s->status === 'pending' && $s->invoice_id === null && $s->due_date && $s->due_date->lte($soon))
            ->map(fn (ContractSchedule $s) => $c->toBase((float) $s->amount) ?? 0));

        return Inertia::render('crm/contracts/Index', [
            'contracts' => $contracts->through(fn (Contract $c) => $this->row($c)),
            'summary' => [
                'active_count' => $active->count(),
                'mrr_usd' => round($active->filter(fn (Contract $c) => $c->isRetainer() && ! $c->isExpired())
                    ->sum(fn (Contract $c) => $c->toBase((float) $c->monthly_fee) ?? 0), 2),
                'due_30_usd' => round($due->sum(), 2),
                'due_30_count' => $due->count(),
                'expiring_count' => $active->filter(fn (Contract $c) => $c->end_date && $c->end_date->lte($soon))->count(),
                'awaiting_signature' => Contract::visibleTo($user)->where('status', 'sent')->count(),
            ],
            'filters' => $f,
            'statuses' => config('crm.contract_statuses'),
            'types' => config('crm.contract_types'),
            'users' => $this->options()['users'],
        ]);
    }

    public function create(Request $request): Response
    {
        $type = in_array($request->query('type'), Contract::TYPES, true) ? $request->query('type') : 'project';
        $form = [
            'type' => $type, 'client_id' => null, 'contact_id' => null, 'opportunity_id' => null, 'quote_id' => null,
            'title' => '', 'currency' => 'USD', 'value' => null, 'monthly_fee' => null, 'term_months' => 12,
            'billing_timing' => 'advance', 'auto_renew' => false, 'notice_days' => 30,
            'start_date' => today()->toDateString(), 'scope' => '', 'terms' => null, 'notes' => '', 'owner_id' => null, 'schedule' => [],
        ];

        if ($quoteId = $request->integer('quote_id')) {
            $quote = Quote::with('items')->findOrFail($quoteId);
            $this->authorizeRecord($quote, 'crm.view');
            $form = array_merge($form, Contracts::fromQuote($quote));
            $form['schedule'] = [['kind' => 'payment', 'title' => 'الدفعة الكاملة', 'details' => '', 'due_date' => null, 'amount' => $form['value']]];
        } elseif ($oppId = $request->integer('opportunity_id')) {
            $opp = Opportunity::findOrFail($oppId);
            $this->authorizeRecord($opp, 'crm.view');
            $form = array_merge($form, ['client_id' => $opp->client_id, 'opportunity_id' => $opp->id, 'title' => $opp->title,
                'currency' => $opp->currency, 'value' => $opp->amount ? (float) $opp->amount : null]);
        } elseif ($clientId = $request->integer('client_id')) {
            $form['client_id'] = $clientId;
            $form['currency'] = Client::find($clientId)?->currency ?? 'USD';
        }
        $form['terms'] ??= Contracts::defaultTerms($form['type']) ?? '';

        return $this->editor($request, null, $form);
    }

    public function store(Request $request): RedirectResponse
    {
        [$data, $rows] = $this->validated($request);
        $client = Client::findOrFail($data['client_id']);
        $this->authorizeRecord($client, 'crm.view');
        $this->checkLinks($data, $client->id);

        $contract = Contracts::saveDraft(new Contract(['created_by' => $request->user()->id]),
            $data + ['owner_id' => $data['owner_id'] ?? $request->user()->id], $rows);
        ActivityLogger::log('crm.contract_created', $contract, "إنشاء مسودة عقد: {$contract->title}");

        return redirect("/crm/contracts/{$contract->id}")->with('success', 'تم حفظ مسودة العقد.');
    }

    public function show(Request $request, Contract $contract): Response
    {
        $this->authorizeRecord($contract, 'crm.view');
        $contract->load(['client:id,name', 'contact:id,name,phone,email', 'opportunity:id,title,stage', 'quote:id,number,title',
            'owner:id,name', 'creator:id,name', 'sourceContract:id,number', 'schedules.doneBy:id,name']);
        $user = $request->user();
        $log = ActivityLog::with('user:id,name')->where('subject_type', 'contract')->where('subject_id', $contract->id)->latest('id')->limit(50)->get();
        $total = $contract->totalValue();

        return Inertia::render('crm/contracts/Show', [
            'contract' => [
                ...$this->row($contract),
                ...$contract->only('client_id', 'billing_timing', 'auto_renew', 'notice_days', 'term_months', 'scope', 'terms', 'notes',
                    'client_signatory', 'termination_reason', 'fx_rate'),
                'value' => $contract->value === null ? null : (float) $contract->value,
                'contact' => $contract->contact?->only('id', 'name', 'phone', 'email'),
                'opportunity' => $contract->opportunity?->only('id', 'title', 'stage'),
                'quote' => $contract->quote?->only('id', 'number', 'title'),
                'creator' => $contract->creator?->only('id', 'name'),
                'source' => $contract->sourceContract?->only('id', 'number'),
                'sent_at' => $contract->sent_at?->toIso8601String(),
                'signed_on' => $contract->signed_on?->toDateString(),
                'completed_at' => $contract->completed_at?->toIso8601String(),
                'terminated_on' => $contract->terminated_on?->toDateString(),
                'cancelled_at' => $contract->cancelled_at?->toIso8601String(),
                'scheduled_total' => $contract->scheduledTotal(),
                'schedule' => $contract->schedules->map(fn (ContractSchedule $s) => [
                    ...$s->only('id', 'kind', 'title', 'details', 'invoice_id'),
                    'status' => $s->displayStatus(),
                    'amount' => $s->amount === null ? null : (float) $s->amount,
                    'percent' => $s->amount !== null && $total > 0 && ! $contract->isRetainer() ? round((float) $s->amount / $total * 100, 1) : null,
                    'due_date' => $s->due_date?->toDateString(),
                    'period_start' => $s->period_start?->toDateString(),
                    'period_end' => $s->period_end?->toDateString(),
                    'done_at' => $s->done_at?->toIso8601String(),
                    'done_by' => $s->doneBy?->name,
                ]),
                'can_edit' => $contract->isVisibleTo($user, 'crm.edit'),
                'can_delete' => $contract->isDraft() && $contract->isVisibleTo($user, 'crm.delete'),
                'missing_rate' => $contract->isDraft() && ExchangeRates::rateFor($contract->currency) === null,
            ],
            'copies' => Contract::visibleTo($user)->where('source_contract_id', $contract->id)->get(['id', 'number', 'status']),
            'project' => $this->projectLink($contract->project_id, $user),
            'log' => $log->map(fn ($l) => [
                'id' => $l->id, 'description' => $l->description,
                'user' => $l->user?->name, 'created_at' => $l->created_at?->toIso8601String(),
            ]),
            'statuses' => config('crm.contract_statuses'),
            'types' => config('crm.contract_types'),
            'scheduleStatuses' => config('crm.contract_schedule_statuses'),
            'billingTimings' => config('crm.billing_timings'),
            'oppStages' => collect(config('crm.opportunity_stages'))->map(fn ($s) => $s['label']),
        ]);
    }

    public function edit(Request $request, Contract $contract): Response|RedirectResponse
    {
        $this->authorizeRecord($contract, 'crm.edit');
        if (! $contract->isDraft()) {
            return redirect("/crm/contracts/{$contract->id}")->with('error', 'العقد صادر ولا يُعدّل. أنشئ نسخة جديدة منه.');
        }
        $contract->load('schedules');

        return $this->editor($request, $contract, [
            ...$contract->only('type', 'client_id', 'contact_id', 'opportunity_id', 'quote_id', 'title', 'currency', 'term_months',
                'billing_timing', 'auto_renew', 'notice_days', 'owner_id'),
            'value' => $contract->value === null ? null : (float) $contract->value,
            'monthly_fee' => $contract->monthly_fee === null ? null : (float) $contract->monthly_fee,
            'start_date' => $contract->start_date?->toDateString(),
            'scope' => $contract->scope ?? '',
            'terms' => $contract->terms ?? '',
            'notes' => $contract->notes ?? '',
            'schedule' => $contract->schedules->filter(fn ($s) => ! $contract->isRetainer() || $s->kind === 'milestone')
                ->map(fn (ContractSchedule $s) => [
                    ...$s->only('kind', 'title'),
                    'details' => $s->details ?? '',
                    'due_date' => $s->due_date?->toDateString(),
                    'amount' => $s->amount === null ? null : (float) $s->amount,
                ])->values(),
        ]);
    }

    public function update(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorizeRecord($contract, 'crm.edit');
        [$data, $rows] = $this->validated($request);
        unset($data['client_id']); // a contract never moves to another client
        $this->checkLinks($data, $contract->client_id);

        Contracts::saveDraft($contract, $data, $rows);
        ActivityLogger::log('crm.contract_updated', $contract, "تعديل مسودة العقد: {$contract->title}");

        return redirect("/crm/contracts/{$contract->id}")->with('success', 'تم حفظ المسودة.');
    }

    public function issue(Contract $contract): RedirectResponse
    {
        $this->authorizeRecord($contract, 'crm.edit');
        Contracts::issue($contract);

        return back()->with('success', "تم إصدار العقد برقم {$contract->number}. نزّل ملف PDF وأرسله للعميل للتوقيع.");
    }

    public function sign(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorizeRecord($contract, 'crm.edit');
        $data = $request->validate([
            'signed_on' => ['required', 'date', 'before_or_equal:today'],
            'client_signatory' => ['nullable', 'string', 'max:190'],
        ]);
        Contracts::sign($contract, Carbon::parse($data['signed_on']), $data['client_signatory'] ?? null);

        return back()->with('success', 'تم تسجيل توقيع العقد وأصبح سارياً.');
    }

    public function complete(Contract $contract): RedirectResponse
    {
        $this->authorizeRecord($contract, 'crm.edit');
        Contracts::complete($contract);

        return back()->with('success', 'تم إنهاء العقد.');
    }

    public function terminate(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorizeRecord($contract, 'crm.edit');
        $data = $request->validate([
            'terminated_on' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        Contracts::terminate($contract, Carbon::parse($data['terminated_on']), $data['reason']);

        return back()->with('success', 'تم فسخ العقد وإلغاء الدفعات والمراحل اللاحقة لتاريخ الفسخ.');
    }

    public function cancel(Contract $contract): RedirectResponse
    {
        $this->authorizeRecord($contract, 'crm.edit');
        Contracts::cancel($contract);

        return back()->with('success', 'تم إلغاء العقد. يبقى رقمه محفوظاً في السجل.');
    }

    public function renew(Contract $contract): RedirectResponse
    {
        $this->authorizeRecord($contract, 'crm.edit');
        Contracts::renew($contract);

        return back()->with('success', "تم تجديد العقد حتى {$contract->end_date->toDateString()}.");
    }

    public function duplicate(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorizeRecord($contract, 'crm.view');
        $contract->load('schedules');
        $copy = Contracts::duplicate($contract, $request->user()->id);

        return redirect("/crm/contracts/{$copy->id}/edit")->with('success', 'أُنشئت مسودة جديدة من العقد. عدّلها ثم أصدرها.');
    }

    public function milestone(Request $request, Contract $contract, ContractSchedule $row): RedirectResponse
    {
        $this->authorizeRecord($contract, 'crm.edit');
        abort_unless($row->contract_id === $contract->id, 404);
        $data = $request->validate(['done' => ['required', 'boolean']]);
        Contracts::setMilestone($row, $data['done'], $request->user()->id);

        return back()->with('success', $data['done'] ? 'تم تسجيل إنجاز المرحلة.' : 'أُعيد فتح المرحلة.');
    }

    /** Only drafts can be archived; issued contracts keep their number and are cancelled or terminated instead. */
    public function destroy(Contract $contract): RedirectResponse
    {
        $this->authorizeRecord($contract, 'crm.delete');
        abort_unless($contract->isDraft(), 422, 'لا يمكن حذف عقد صادر؛ ألغِه أو افسخه بدلاً من ذلك.');
        $contract->delete();
        ActivityLogger::log('crm.contract_archived', $contract, "حذف مسودة العقد: {$contract->title}");

        return redirect('/crm/contracts')->with('success', 'تم حذف المسودة.');
    }

    public function pdf(Request $request, Contract $contract): HttpResponse
    {
        $this->authorizeRecord($contract, 'crm.view');
        $name = ContractPdf::filename($contract);
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response(ContractPdf::render($contract), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "$disposition; filename=\"$name\"",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @param array<string, mixed> $form */
    private function editor(Request $request, ?Contract $contract, array $form): Response
    {
        $user = $request->user();
        $with = [
            'contacts:id,client_id,name',
            'opportunities' => fn ($q) => $q->open()->select('id', 'client_id', 'title'),
            'quotes' => fn ($q) => $q->whereIn('status', ['sent', 'accepted'])->select('id', 'client_id', 'number', 'title'),
        ];
        $clients = Client::visibleTo($user)->orderBy('name')->limit(1000)->with($with)->get(['id', 'name', 'currency']);
        if ($form['client_id'] && ! $clients->contains('id', $form['client_id'])) {
            $c = Client::with($with)->find($form['client_id'], ['id', 'name', 'currency']);
            $c && $clients->prepend($c);
        }

        return Inertia::render('crm/contracts/Edit', [
            'contract' => $contract?->only('id', 'title'),
            'form' => $form,
            'clients' => $clients->map(fn (Client $c) => [
                ...$c->only('id', 'name', 'currency'),
                'contacts' => $c->contacts->map->only('id', 'name')->values(),
                'opportunities' => $c->opportunities->map->only('id', 'title')->values(),
                'quotes' => $c->quotes->map->only('id', 'number', 'title')->values(),
            ]),
            'types' => config('crm.contract_types'),
            'billingTimings' => config('crm.billing_timings'),
            'defaultTerms' => ['project' => Contracts::defaultTerms('project') ?? '', 'retainer' => Contracts::defaultTerms('retainer') ?? ''],
            'users' => $this->options()['users'],
            'currencies' => $this->options()['currencies'],
        ]);
    }

    /** The opportunity and quote must belong to the contract's client. @param array<string, mixed> $data */
    private function checkLinks(array $data, int $clientId): void
    {
        Quotes::attachableOpportunity($data['opportunity_id'] ?? null, $clientId);
        if (! empty($data['quote_id']) && (int) Quote::whereKey($data['quote_id'])->value('client_id') !== $clientId) {
            throw ValidationException::withMessages(['quote_id' => 'عرض السعر لا يتبع هذا العميل.']);
        }
    }

    /** @return array<string, mixed> */
    private function row(Contract $c): array
    {
        $next = $c->schedules->first(fn (ContractSchedule $s) => $s->kind === 'payment' && $s->status === 'pending' && $s->invoice_id === null);

        return [
            ...$c->only('id', 'number', 'title', 'type', 'currency'),
            'status' => $c->displayStatus(),
            'issue_date' => $c->issue_date?->toDateString(),
            'start_date' => $c->start_date?->toDateString(),
            'end_date' => $c->end_date?->toDateString(),
            'client' => $c->client?->only('id', 'name'),
            'owner' => $c->owner?->only('id', 'name'),
            'monthly_fee' => $c->monthly_fee === null ? null : (float) $c->monthly_fee,
            'total' => $c->totalValue(),
            'total_usd' => $c->totalInBase(),
            'next_payment' => $next && in_array($c->status, ['sent', 'active'], true)
                ? ['due_date' => $next->due_date?->toDateString(), 'amount' => (float) $next->amount, 'overdue' => $next->displayStatus() === 'overdue']
                : null,
        ];
    }

    /** @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(Contract::TYPES)],
            'client_id' => ['required', 'exists:clients,id'],
            'contact_id' => ['nullable', Rule::exists('contacts', 'id')->where('client_id', $request->input('client_id'))],
            'opportunity_id' => ['nullable', 'exists:opportunities,id'],
            'quote_id' => ['nullable', 'exists:quotes,id'],
            'title' => ['required', 'string', 'max:190'],
            'currency' => ['required', 'exists:currencies,code'],
            'value' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'monthly_fee' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'term_months' => ['nullable', 'integer', 'between:1,60'],
            'billing_timing' => ['nullable', Rule::in(array_keys(config('crm.billing_timings')))],
            'auto_renew' => ['boolean'],
            'notice_days' => ['nullable', 'integer', 'between:0,365'],
            'start_date' => ['nullable', 'date'],
            'scope' => ['nullable', 'string', 'max:10000'],
            'terms' => ['nullable', 'string', 'max:20000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'owner_id' => ['nullable', 'exists:users,id'],
            'schedule' => ['array', 'max:120'],
            'schedule.*.kind' => ['required', Rule::in(['payment', 'milestone'])],
            'schedule.*.title' => ['required', 'string', 'max:190'],
            'schedule.*.details' => ['nullable', 'string', 'max:3000'],
            'schedule.*.due_date' => ['nullable', 'date'],
            'schedule.*.amount' => ['nullable', 'required_if:schedule.*.kind,payment', 'numeric', 'min:0', 'max:999999999999'],
        ], [
            'schedule.*.title.required' => 'اكتب عنوان البند.',
            'schedule.*.amount.required_if' => 'اكتب مبلغ الدفعة.',
        ]);
        $rows = $data['schedule'] ?? [];
        unset($data['schedule']);
        $data['auto_renew'] = (bool) ($data['auto_renew'] ?? false);

        return [$data, array_values($rows)];
    }
}

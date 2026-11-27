<?php

namespace App\Http\Controllers\Crm;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\ActivityLogger;
use App\Services\ExchangeRates;
use App\Services\Sales\QuotePdf;
use App\Services\Sales\Quotes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class QuoteController extends CrmController
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $f = $request->only('search', 'status', 'owner', 'client');

        $quotes = Quote::visibleTo($user)->with(['client:id,name', 'owner:id,name', 'items'])
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('title', 'like', "%$s%")->orWhere('number', 'like', "%$s%")
                ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%$s%"))))
            ->when($f['status'] ?? null, function ($q, $st) {
                match ($st) {
                    'expired' => $q->where('status', 'sent')->whereDate('valid_until', '<', today()),
                    'sent' => $q->where('status', 'sent')->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', today())),
                    default => $q->where('status', $st),
                };
            })
            ->when($f['owner'] ?? null, fn ($q, $v) => $q->where('owner_id', $v))
            ->when($f['client'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
            ->latest('id')->paginate(30)->withQueryString();

        $open = Quote::visibleTo($user)->with('items')->where('status', 'sent')->get();

        return Inertia::render('crm/quotes/Index', [
            'quotes' => $quotes->through(fn (Quote $q) => $this->row($q)),
            'summary' => [
                'drafts' => Quote::visibleTo($user)->where('status', 'draft')->count(),
                'open_count' => $open->count(),
                'open_usd' => round($open->sum(fn (Quote $q) => $q->totalInBase() ?? 0), 2),
                'accepted_month_usd' => round(Quote::visibleTo($user)->with('items')->where('status', 'accepted')
                    ->where('accepted_at', '>=', now()->startOfMonth())->get()->sum(fn (Quote $q) => $q->totalInBase() ?? 0), 2),
            ],
            'filters' => $f,
            'statuses' => config('crm.quote_statuses'),
            'users' => $this->options()['users'],
        ]);
    }

    public function create(Request $request): Response
    {
        $clientId = $request->integer('client_id') ?: null;
        $oppId = $request->integer('opportunity_id') ?: null;
        $currency = 'USD';
        $title = '';
        if ($oppId) {
            $opp = Opportunity::findOrFail($oppId);
            $this->authorizeRecord($opp, 'crm.view');
            $clientId = $opp->client_id;
            $currency = $opp->currency;
            $title = $opp->title;
        } elseif ($clientId) {
            $currency = Client::find($clientId)?->currency ?? 'USD';
        }

        return $this->editor($request, null, [
            'client_id' => $clientId,
            'opportunity_id' => $oppId,
            'contact_id' => null,
            'title' => $title,
            'currency' => $currency,
            'valid_until' => today()->addDays(Quotes::validityDays())->toDateString(),
            'intro' => '',
            'terms' => Quotes::defaultTerms() ?? '',
            'notes' => '',
            'owner_id' => null,
            'items' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$data, $items] = $this->validated($request);
        $client = Client::findOrFail($data['client_id']);
        $this->authorizeRecord($client, 'crm.view');
        Quotes::attachableOpportunity($data['opportunity_id'] ?? null, $client->id);

        $quote = Quotes::saveDraft(new Quote([
            'created_by' => $request->user()->id,
        ]), $data + ['owner_id' => $data['owner_id'] ?? $request->user()->id], $items);
        ActivityLogger::log('crm.quote_created', $quote, "إنشاء مسودة عرض سعر: {$quote->title}");

        return redirect("/crm/quotes/{$quote->id}")->with('success', 'تم حفظ مسودة العرض.');
    }

    public function show(Request $request, Quote $quote): Response
    {
        $this->authorizeRecord($quote, 'crm.view');
        $quote->load(['client:id,name', 'contact:id,name,phone,email', 'opportunity:id,title,stage', 'owner:id,name', 'creator:id,name', 'sourceQuote:id,number', 'items']);
        $user = $request->user();

        $log = ActivityLog::with('user:id,name')->where('subject_type', 'quote')->where('subject_id', $quote->id)->latest('id')->limit(50)->get();

        return Inertia::render('crm/quotes/Show', [
            'quote' => [
                ...$this->row($quote),
                ...$quote->only('client_id', 'opportunity_id', 'intro', 'terms', 'notes', 'rejection_reason', 'fx_rate'),
                'contact' => $quote->contact?->only('id', 'name', 'phone', 'email'),
                'opportunity' => $quote->opportunity?->only('id', 'title', 'stage'),
                'creator' => $quote->creator?->only('id', 'name'),
                'source' => $quote->sourceQuote?->only('id', 'number'),
                'sent_at' => $quote->sent_at?->toIso8601String(),
                'accepted_at' => $quote->accepted_at?->toIso8601String(),
                'rejected_at' => $quote->rejected_at?->toIso8601String(),
                'cancelled_at' => $quote->cancelled_at?->toIso8601String(),
                'created_at' => $quote->created_at->toIso8601String(),
                'items' => $quote->items->map(fn (QuoteItem $it) => $this->itemPayload($it)),
                'totals' => $quote->totals(),
                'can_edit' => $quote->isVisibleTo($user, 'crm.edit'),
                'can_delete' => $quote->isDraft() && $quote->isVisibleTo($user, 'crm.delete'),
                'missing_rate' => $quote->isDraft() && ExchangeRates::rateFor($quote->currency) === null,
            ],
            'copies' => Quote::visibleTo($user)->where('source_quote_id', $quote->id)->get(['id', 'number', 'status']),
            'contracts' => $this->contractList($quote->contracts(), $user),
            'contractStatuses' => config('crm.contract_statuses'),
            'project' => $this->projectLink($quote->project_id, $user),
            'log' => $log->map(fn ($l) => [
                'id' => $l->id, 'action' => $l->action, 'description' => $l->description,
                'user' => $l->user?->name, 'created_at' => $l->created_at?->toIso8601String(),
            ]),
            'statuses' => config('crm.quote_statuses'),
            'oppStages' => collect(config('crm.opportunity_stages'))->map(fn ($s) => $s['label']),
        ]);
    }

    public function edit(Request $request, Quote $quote): Response|RedirectResponse
    {
        $this->authorizeRecord($quote, 'crm.edit');
        if (! $quote->isDraft()) {
            return redirect("/crm/quotes/{$quote->id}")->with('error', 'العرض صادر ولا يُعدّل. أنشئ نسخة جديدة منه.');
        }
        $quote->load('items');

        return $this->editor($request, $quote, [
            ...$quote->only('client_id', 'opportunity_id', 'contact_id', 'title', 'currency', 'owner_id'),
            'valid_until' => $quote->valid_until?->toDateString(),
            'intro' => $quote->intro ?? '',
            'terms' => $quote->terms ?? '',
            'notes' => $quote->notes ?? '',
            'items' => $quote->items->map(fn (QuoteItem $it) => [
                ...$it->only('service_id', 'service_category_id', 'name', 'details', 'unit'),
                'quantity' => (float) $it->quantity,
                'unit_price' => (float) $it->unit_price,
                'discount_percent' => (float) $it->discount_percent,
            ]),
        ]);
    }

    public function update(Request $request, Quote $quote): RedirectResponse
    {
        $this->authorizeRecord($quote, 'crm.edit');
        [$data, $items] = $this->validated($request);
        unset($data['client_id']); // a quote never moves to another client
        Quotes::attachableOpportunity($data['opportunity_id'] ?? null, $quote->client_id);

        Quotes::saveDraft($quote, $data, $items);
        ActivityLogger::log('crm.quote_updated', $quote, "تعديل مسودة عرض السعر: {$quote->title}");

        return redirect("/crm/quotes/{$quote->id}")->with('success', 'تم حفظ المسودة.');
    }

    public function issue(Quote $quote): RedirectResponse
    {
        $this->authorizeRecord($quote, 'crm.edit');
        Quotes::issue($quote);

        return back()->with('success', "تم إصدار العرض برقم {$quote->number}. نزّل ملف PDF وأرسله للعميل.");
    }

    public function accept(Quote $quote): RedirectResponse
    {
        $this->authorizeRecord($quote, 'crm.edit');
        Quotes::accept($quote);

        return back()->with('success', 'تم تسجيل قبول العرض، ونُقلت الفرصة إلى «مكسوبة».');
    }

    public function reject(Request $request, Quote $quote): RedirectResponse
    {
        $this->authorizeRecord($quote, 'crm.edit');
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        Quotes::reject($quote, $data['reason']);

        return back()->with('success', 'تم تسجيل رفض العرض.');
    }

    public function cancel(Quote $quote): RedirectResponse
    {
        $this->authorizeRecord($quote, 'crm.edit');
        Quotes::cancel($quote);

        return back()->with('success', 'تم إلغاء العرض. يبقى رقمه محفوظاً في السجل.');
    }

    public function duplicate(Request $request, Quote $quote): RedirectResponse
    {
        $this->authorizeRecord($quote, 'crm.view');
        $copy = Quotes::duplicate($quote, $request->user()->id);

        return redirect("/crm/quotes/{$copy->id}/edit")->with('success', 'أُنشئت مسودة جديدة من العرض. عدّلها ثم أصدرها.');
    }

    /** Only drafts can be archived; issued quotes keep their number and are cancelled instead. */
    public function destroy(Quote $quote): RedirectResponse
    {
        $this->authorizeRecord($quote, 'crm.delete');
        abort_unless($quote->isDraft(), 422, 'لا يمكن حذف عرض صادر؛ ألغِه بدلاً من ذلك.');
        $quote->delete();
        ActivityLogger::log('crm.quote_archived', $quote, "حذف مسودة عرض السعر: {$quote->title}");

        return redirect('/crm/quotes')->with('success', 'تم حذف المسودة.');
    }

    public function pdf(Request $request, Quote $quote): HttpResponse
    {
        $this->authorizeRecord($quote, 'crm.view');
        $name = QuotePdf::filename($quote);
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response(QuotePdf::render($quote), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "$disposition; filename=\"$name\"",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @param array<string, mixed> $form */
    private function editor(Request $request, ?Quote $quote, array $form): Response
    {
        $user = $request->user();
        $clients = Client::visibleTo($user)->orderBy('name')->limit(1000)
            ->with(['contacts:id,client_id,name', 'opportunities' => fn ($q) => $q->open()->select('id', 'client_id', 'title')])
            ->get(['id', 'name', 'currency']);
        // Keep the quote's own client in the list even if the user cannot list it any more.
        if ($form['client_id'] && ! $clients->contains('id', $form['client_id'])) {
            $c = Client::with(['contacts:id,client_id,name', 'opportunities' => fn ($q) => $q->open()->select('id', 'client_id', 'title')])->find($form['client_id'], ['id', 'name', 'currency']);
            $c && $clients->prepend($c);
        }

        return Inertia::render('crm/quotes/Edit', [
            'quote' => $quote?->only('id', 'title'),
            'form' => $form,
            'clients' => $clients->map(fn (Client $c) => [
                ...$c->only('id', 'name', 'currency'),
                'contacts' => $c->contacts->map->only('id', 'name')->values(),
                'opportunities' => $c->opportunities->map->only('id', 'title')->values(),
            ]),
            'services' => Service::active()->with('category:id,name')->orderBy('sort')->orderBy('name')
                ->get(['id', 'service_category_id', 'name', 'description', 'unit', 'price', 'currency']),
            'categories' => ServiceCategory::where('is_active', true)->orderBy('sort')->get(['id', 'name', 'tax_rate']),
            'units' => config('crm.service_units'),
            'users' => $this->options()['users'],
            'currencies' => $this->options()['currencies'],
        ]);
    }

    /** @return array<string, mixed> */
    private function row(Quote $q): array
    {
        $totals = $q->totals();

        return [
            ...$q->only('id', 'number', 'title', 'currency'),
            'status' => $q->displayStatus(),
            'issue_date' => $q->issue_date?->toDateString(),
            'valid_until' => $q->valid_until?->toDateString(),
            'client' => $q->client?->only('id', 'name'),
            'owner' => $q->owner?->only('id', 'name'),
            'total' => $totals['total'],
            'total_usd' => $q->totalInBase(),
            'item_count' => $q->items->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function itemPayload(QuoteItem $it): array
    {
        return [
            ...$it->only('id', 'service_id', 'name', 'details', 'unit'),
            'quantity' => (float) $it->quantity,
            'unit_price' => (float) $it->unit_price,
            'discount_percent' => (float) $it->discount_percent,
            'tax_rate' => (float) $it->tax_rate,
            'gross' => $it->gross(),
            'net' => $it->net(),
            'tax' => $it->taxAmount(),
        ];
    }

    /** @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'opportunity_id' => ['nullable', 'exists:opportunities,id'],
            'contact_id' => ['nullable', Rule::exists('contacts', 'id')->where('client_id', $request->input('client_id'))],
            'title' => ['required', 'string', 'max:190'],
            'currency' => ['required', 'exists:currencies,code'],
            'valid_until' => ['nullable', 'date'],
            'intro' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'owner_id' => ['nullable', 'exists:users,id'],
            'items' => ['array', 'max:100'],
            'items.*.service_id' => ['nullable', 'exists:services,id'],
            'items.*.service_category_id' => ['nullable', 'exists:service_categories,id'],
            'items.*.name' => ['required', 'string', 'max:190'],
            'items.*.details' => ['nullable', 'string', 'max:3000'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'items.*.unit' => ['nullable', 'string', 'max:32'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'between:0,100'],
        ], [
            'items.*.name.required' => 'اكتب اسم البند.',
            'items.*.quantity.gt' => 'الكمية يجب أن تكون أكبر من صفر.',
        ]);
        $items = $data['items'] ?? [];
        unset($data['items']);

        return [$data, array_values($items)];
    }
}

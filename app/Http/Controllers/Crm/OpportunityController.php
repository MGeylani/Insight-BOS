<?php

namespace App\Http\Controllers\Crm;

use App\Models\Client;
use App\Models\Opportunity;
use App\Services\ActivityLogger;
use App\Services\Crm\OpportunityStages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OpportunityController extends CrmController
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $f = $request->only('search', 'owner', 'closed');

        $opps = Opportunity::visibleTo($user)->with(['client:id,name', 'owner:id,name'])
            ->when(! ($f['closed'] ?? false), fn ($q) => $q->where(fn ($q) => $q->open()->orWhere('closed_at', '>=', now()->subDays(30))))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('title', 'like', "%$s%")->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%$s%"))))
            ->when($f['owner'] ?? null, fn ($q, $v) => $q->where('owner_id', $v))
            ->orderBy('expected_close_date')->limit(500)->get();

        return Inertia::render('crm/opportunities/Index', [
            'opportunities' => $opps->map(fn (Opportunity $o) => $this->card($o)),
            'forecast' => $this->forecast(Opportunity::visibleTo($user)->open()->get()),
            'filters' => $f,
            'clients' => Client::visibleTo($user)->orderBy('name')->limit(500)->get(['id', 'name']),
            ...$this->options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request) + $request->validate([
            'stage' => ['required', Rule::in(['discovery', 'proposal', 'sent', 'negotiation'])],
        ]);
        $client = Client::findOrFail($data['client_id']);
        $this->authorizeRecord($client, 'crm.view');

        $opp = new Opportunity($data + ['created_by' => $request->user()->id, 'owner_id' => $data['owner_id'] ?? $request->user()->id]);
        OpportunityStages::move($opp, $data['stage']);
        ActivityLogger::log('crm.opportunity_created', $opp, "إضافة فرصة {$opp->title}");

        return redirect("/crm/opportunities/{$opp->id}")->with('success', 'تمت إضافة الفرصة.');
    }

    public function show(Request $request, Opportunity $opportunity): Response
    {
        $this->authorizeRecord($opportunity, 'crm.view');
        $o = $opportunity->load([
            'client:id,name', 'contact:id,name,phone,email', 'lead:id,name', 'owner:id,name',
            'stageChanges.user:id,name',
            'activities' => fn ($q) => $q->with('user:id,name', 'creator:id,name')->latest(),
        ]);

        return Inertia::render('crm/opportunities/Show', [
            'opportunity' => [
                ...$this->card($o),
                ...$o->only('contact_id', 'owner_id', 'notes', 'lost_note', 'client_id'),
                'contact' => $o->contact?->only('id', 'name', 'phone', 'email'),
                'lead' => $o->lead?->only('id', 'name'),
                'closed_at' => $o->closed_at?->toIso8601String(),
                'created_at' => $o->created_at->toIso8601String(),
                'can_edit' => $o->isVisibleTo($request->user(), 'crm.edit'),
            ],
            'history' => $o->stageChanges->map(fn ($c) => [
                ...$c->only('id', 'from_stage', 'to_stage', 'probability'),
                'user' => $c->user?->only('id', 'name'),
                'created_at' => $c->created_at->toIso8601String(),
            ]),
            'activities' => $o->activities->map(fn ($a) => $this->activityPayload($a)),
            'contacts' => $o->client?->contacts()->get(['id', 'name']) ?? [],
            ...$this->options(),
        ]);
    }

    public function update(Request $request, Opportunity $opportunity): RedirectResponse
    {
        $this->authorizeRecord($opportunity, 'crm.edit');
        $data = $this->validated($request);
        unset($data['client_id']); // an opportunity never moves to another client
        $opportunity->update($data);
        ActivityLogger::log('crm.opportunity_updated', $opportunity, "تعديل فرصة {$opportunity->title}", ActivityLogger::diff($opportunity));

        return back()->with('success', 'تم حفظ التعديلات.');
    }

    public function stage(Request $request, Opportunity $opportunity): RedirectResponse
    {
        $this->authorizeRecord($opportunity, 'crm.edit');
        $data = $request->validate([
            'stage' => ['required', Rule::in(array_keys(config('crm.opportunity_stages')))],
            'probability' => ['nullable', 'integer', 'between:0,100'],
            'lost_reason' => ['nullable', 'string'],
            'lost_note' => ['nullable', 'string', 'max:2000'],
        ]);

        OpportunityStages::move($opportunity, $data['stage'], $data['probability'] ?? null, $data['lost_reason'] ?? null, $data['lost_note'] ?? null);

        return back()->with('success', 'تم تحديث المرحلة.');
    }

    public function destroy(Opportunity $opportunity): RedirectResponse
    {
        $this->authorizeRecord($opportunity, 'crm.delete');
        $opportunity->delete();
        ActivityLogger::log('crm.opportunity_archived', $opportunity, "أرشفة فرصة {$opportunity->title}");

        return redirect('/crm/opportunities')->with('success', 'تمت أرشفة الفرصة.');
    }

    /**
     * Weighted forecast of open opportunities in USD, grouped by expected close month.
     *
     * @return array<string, mixed>
     */
    private function forecast($open): array
    {
        $months = [];
        $total = 0;
        $weighted = 0;
        $missingRate = [];
        foreach ($open as $o) {
            $base = $o->amountInBase();
            if ($base === null) {
                $missingRate[$o->currency] = true;

                continue;
            }
            $w = $base * $o->probability / 100;
            $total += $base;
            $weighted += $w;
            $key = $o->expected_close_date ? Carbon::parse($o->expected_close_date)->format('Y-m') : 'none';
            $months[$key] ??= ['month' => $key, 'total' => 0, 'weighted' => 0, 'count' => 0];
            $months[$key]['total'] += $base;
            $months[$key]['weighted'] += $w;
            $months[$key]['count']++;
        }
        ksort($months);

        return [
            'total' => round($total, 2),
            'weighted' => round($weighted, 2),
            'count' => $open->count(),
            'months' => array_values(array_map(fn ($m) => [...$m, 'total' => round($m['total'], 2), 'weighted' => round($m['weighted'], 2)], $months)),
            'missing_rates' => array_keys($missingRate),
        ];
    }

    /** @return array<string, mixed> */
    private function card(Opportunity $o): array
    {
        return [
            ...$o->only('id', 'title', 'stage', 'probability', 'amount', 'currency', 'lost_reason'),
            'expected_close_date' => $o->expected_close_date?->toDateString(),
            'client' => $o->client?->only('id', 'name'),
            'owner' => $o->owner?->only('id', 'name'),
            'weighted_usd' => $o->weightedInBase(),
            'overdue' => ! $o->isClosed() && $o->expected_close_date && $o->expected_close_date->isPast(),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'contact_id' => ['nullable', 'exists:contacts,id'],
            'title' => ['required', 'string', 'max:190'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'exists:currencies,code'],
            'expected_close_date' => ['nullable', 'date'],
            'owner_id' => ['nullable', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}

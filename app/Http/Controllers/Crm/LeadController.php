<?php

namespace App\Http\Controllers\Crm;

use App\Models\Client;
use App\Models\Lead;
use App\Services\ActivityLogger;
use App\Services\Crm\DuplicateFinder;
use App\Services\Crm\LeadConverter;
use App\Services\Crm\LeadMerger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LeadController extends CrmController
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $f = $request->only('search', 'owner', 'source', 'rating');

        $leads = Lead::visibleTo($user)->open()->with('owner:id,name')->withCount(['activities as pending_count' => fn ($q) => $q->whereNull('completed_at')])
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%$s%")->orWhere('company_name', 'like', "%$s%")->orWhere('email', 'like', "%$s%")->orWhere('phone', 'like', "%$s%")))
            ->when($f['owner'] ?? null, fn ($q, $v) => $q->where('owner_id', $v))
            ->when($f['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->when($f['rating'] ?? null, fn ($q, $v) => $q->where('rating', $v))
            ->orderBy('position')->orderByDesc('id')->limit(500)->get()
            ->map(fn (Lead $l) => $this->card($l));

        return Inertia::render('crm/leads/Index', [
            'leads' => $leads,
            'filters' => $f,
            'converted_count' => Lead::visibleTo($user)->where('stage', Lead::CONVERTED)->count(),
            ...$this->options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $lead = Lead::create($data + [
            'created_by' => $request->user()->id,
            'owner_id' => $data['owner_id'] ?? $request->user()->id,
            'position' => (int) Lead::where('stage', $data['stage'] ?? 'new')->max('position') + 1,
        ]);
        ActivityLogger::log('crm.lead_created', $lead, "إضافة محتمل {$lead->name}");

        return redirect("/crm/leads/{$lead->id}")->with('success', 'تمت إضافة العميل المحتمل.');
    }

    public function show(Request $request, Lead $lead): Response|RedirectResponse
    {
        $this->authorizeRecord($lead, 'crm.view');
        if ($lead->merged_into_id) {
            return redirect("/crm/leads/{$lead->merged_into_id}")->with('error', 'هذا السجل دُمج في سجل آخر.');
        }

        $lead->load(['owner:id,name', 'client:id,name', 'activities' => fn ($q) => $q->with('user:id,name', 'creator:id,name')->latest()]);

        return Inertia::render('crm/leads/Show', [
            'lead' => [
                ...$lead->only('id', 'name', 'company_name', 'job_title', 'email', 'phone', 'source', 'stage', 'rating', 'expected_value', 'currency', 'interest', 'owner_id', 'notes'),
                'owner' => $lead->owner?->only('id', 'name'),
                'client' => $lead->client?->only('id', 'name'),
                'converted_at' => $lead->converted_at?->toIso8601String(),
                'created_at' => $lead->created_at->toIso8601String(),
                'can_edit' => $lead->isVisibleTo($request->user(), 'crm.edit'),
            ],
            'activities' => $lead->activities->map(fn ($a) => $this->activityPayload($a)),
            'duplicates' => $lead->isConverted() ? [] : DuplicateFinder::find($lead->only('email', 'phone', 'company_name'), $lead->id),
            'clients' => Client::visibleTo($request->user())->orderBy('name')->limit(500)->get(['id', 'name']),
            ...$this->options(),
        ]);
    }

    public function update(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeRecord($lead, 'crm.edit');
        abort_if($lead->isConverted(), 422, 'Converted lead');
        $lead->update($this->validated($request));
        ActivityLogger::log('crm.lead_updated', $lead, "تعديل محتمل {$lead->name}", ActivityLogger::diff($lead));

        return back()->with('success', 'تم حفظ التعديلات.');
    }

    /** Kanban drag & drop: new stage and the full ordered id list of that column. */
    public function move(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeRecord($lead, 'crm.edit');
        $data = $request->validate([
            'stage' => ['required', Rule::in(array_keys(config('crm.lead_stages')))],
            'order' => ['array'],
            'order.*' => ['integer'],
        ]);
        abort_if($lead->isConverted(), 422);

        DB::transaction(function () use ($lead, $data) {
            $from = $lead->stage;
            $lead->update(['stage' => $data['stage']]);
            foreach (array_values($data['order'] ?? []) as $i => $id) {
                Lead::whereKey($id)->where('stage', $data['stage'])->update(['position' => $i]);
            }
            if ($from !== $data['stage']) {
                ActivityLogger::log('crm.lead_stage', $lead, "نقل {$lead->name} إلى «".config("crm.lead_stages.{$data['stage']}").'»', ['stage' => ['from' => $from, 'to' => $data['stage']]]);
            }
        });

        return back();
    }

    public function destroy(Lead $lead): RedirectResponse
    {
        $this->authorizeRecord($lead, 'crm.delete');
        $lead->delete();
        ActivityLogger::log('crm.lead_archived', $lead, "أرشفة محتمل {$lead->name}");

        return redirect('/crm/leads')->with('success', 'تمت أرشفة السجل.');
    }

    /** Live duplicate check used by the create form. */
    public function duplicates(Request $request): JsonResponse
    {
        return response()->json(DuplicateFinder::find(
            $request->only('email', 'phone', 'company_name'),
            $request->integer('ignore') ?: null,
        ));
    }

    public function merge(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeRecord($lead, 'crm.edit');
        $data = $request->validate(['duplicate_id' => ['required', 'integer', 'exists:leads,id']]);
        $dupe = Lead::findOrFail($data['duplicate_id']);
        $this->authorizeRecord($dupe, 'crm.delete');
        abort_if($lead->isConverted() || $dupe->isConverted() || $lead->is($dupe), 422);

        LeadMerger::merge($lead, $dupe);

        return back()->with('success', 'تم دمج السجلين.');
    }

    public function convert(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeRecord($lead, 'crm.convert');
        $data = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'client.name' => ['nullable', 'string', 'max:190'],
            'client.type' => ['nullable', Rule::in(array_keys(config('crm.client_types')))],
            'contact.name' => ['nullable', 'string', 'max:190'],
            'contact.job_title' => ['nullable', 'string', 'max:120'],
            'create_opportunity' => ['boolean'],
            'opportunity.title' => ['required_if:create_opportunity,true', 'nullable', 'string', 'max:190'],
            'opportunity.amount' => ['nullable', 'numeric', 'min:0'],
            'opportunity.currency' => ['nullable', 'exists:currencies,code'],
            'opportunity.stage' => ['nullable', Rule::in(['discovery', 'proposal', 'sent', 'negotiation'])],
            'opportunity.expected_close_date' => ['nullable', 'date'],
        ]);

        $r = LeadConverter::convert($lead, [
            'client_id' => $data['client_id'] ?? null,
            'client' => $data['client'] ?? [],
            'contact' => $data['contact'] ?? [],
            'opportunity' => ($data['create_opportunity'] ?? false) ? ($data['opportunity'] ?? []) : null,
        ]);

        $to = $r['opportunity'] ? "/crm/opportunities/{$r['opportunity']->id}" : "/crm/clients/{$r['client']->id}";

        return redirect($to)->with('success', 'تم التحويل إلى عميل.');
    }

    /** @return array<string, mixed> */
    private function card(Lead $l): array
    {
        return [
            ...$l->only('id', 'name', 'company_name', 'stage', 'rating', 'source', 'expected_value', 'currency', 'interest'),
            'owner' => $l->owner?->only('id', 'name'),
            'pending_count' => $l->pending_count,
            'created_at' => $l->created_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'company_name' => ['nullable', 'string', 'max:190'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'source' => ['nullable', Rule::in(array_keys(config('crm.lead_sources')))],
            'stage' => ['sometimes', Rule::in(array_keys(config('crm.lead_stages')))],
            'rating' => ['nullable', Rule::in(array_keys(config('crm.lead_ratings')))],
            'expected_value' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'exists:currencies,code'],
            'interest' => ['nullable', 'string', 'max:190'],
            'owner_id' => ['nullable', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}

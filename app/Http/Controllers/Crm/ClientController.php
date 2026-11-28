<?php

namespace App\Http\Controllers\Crm;

use App\Models\Client;
use App\Models\Opportunity;
use App\Services\ActivityLogger;
use App\Services\Crm\DuplicateFinder;
use App\Services\Storage\Attachments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends CrmController
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $owner = $request->query('owner');

        $clients = Client::visibleTo($request->user())
            ->with(['owner:id,name', 'primaryContact:id,client_id,name,phone'])
            ->withCount(['opportunities as open_opportunities' => fn ($q) => $q->open()])
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%$search%")->orWhere('legal_name', 'like', "%$search%")->orWhere('email', 'like', "%$search%")->orWhere('phone', 'like', "%$search%")))
            ->when($owner, fn ($q, $v) => $q->where('owner_id', $v))
            ->orderBy('name')->paginate(25)->withQueryString()
            ->through(fn (Client $c) => [
                ...$c->only('id', 'name', 'type', 'industry', 'email', 'phone', 'city', 'country', 'open_opportunities'),
                'owner' => $c->owner?->only('id', 'name'),
                'contact' => $c->primaryContact?->only('name', 'phone'),
            ]);

        return Inertia::render('crm/clients/Index', [
            'clients' => $clients,
            'filters' => ['search' => $search, 'owner' => $owner],
            ...$this->options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $client = Client::create($data + ['created_by' => $request->user()->id, 'owner_id' => $data['owner_id'] ?? $request->user()->id]);
        ActivityLogger::log('crm.client_created', $client, "إضافة عميل {$client->name}");

        return redirect("/crm/clients/{$client->id}")->with('success', 'تمت إضافة العميل.');
    }

    /** Client 360°: profile, contacts, opportunities, activity history and lead origin. */
    public function show(Request $request, Client $client): Response
    {
        $this->authorizeRecord($client, 'crm.view');
        $client->load([
            'owner:id,name', 'creator:id,name', 'contacts',
            'opportunities' => fn ($q) => $q->with('owner:id,name')->latest(),
            'leads:id,client_id,name,source,converted_at',
            'activities' => fn ($q) => $q->with('user:id,name', 'creator:id,name')->latest(),
        ]);

        $opps = $client->opportunities;
        $won = $opps->where('stage', 'won');
        $open = $opps->filter(fn (Opportunity $o) => ! $o->isClosed());

        return Inertia::render('crm/clients/Show', [
            'client' => [
                ...$client->only('id', 'name', 'legal_name', 'type', 'industry', 'email', 'phone', 'website', 'country', 'city', 'address', 'tax_number', 'currency', 'source', 'owner_id', 'notes', 'task_prefix', 'is_internal'),
                'owner' => $client->owner?->only('id', 'name'),
                'creator' => $client->creator?->only('id', 'name'),
                'created_at' => $client->created_at->toIso8601String(),
                'can_edit' => $client->isVisibleTo($request->user(), 'crm.edit'),
            ],
            'contacts' => $client->contacts->map(fn ($c) => $c->only('id', 'name', 'job_title', 'email', 'phone', 'whatsapp', 'is_primary', 'notes')),
            'opportunities' => $opps->map(fn (Opportunity $o) => [
                ...$o->only('id', 'title', 'stage', 'probability', 'amount', 'currency'),
                'expected_close_date' => $o->expected_close_date?->toDateString(),
                'owner' => $o->owner?->only('id', 'name'),
            ]),
            'leads' => $client->leads->map(fn ($l) => [...$l->only('id', 'name', 'source'), 'converted_at' => $l->converted_at?->toIso8601String()]),
            'activities' => $client->activities->map(fn ($a) => $this->activityPayload($a)),
            'quotes' => $this->quoteList($client->quotes(), $request->user()),
            'quoteStatuses' => config('crm.quote_statuses'),
            'contracts' => $this->contractList($client->contracts(), $request->user()),
            'contractStatuses' => config('crm.contract_statuses'),
            'projects' => $this->projectList($client, $request->user()),
            'invoices' => \App\Services\Finance\InvoiceLists::for($request->user(), fn ($q) => $q->where('client_id', $client->id)),
            'invoiceStatuses' => config('finance.invoice_statuses'),
            'projectStatuses' => config('projects.statuses'),
            'files' => Attachments::payload($client, $request->user()),
            'stats' => [
                'won_total' => round($won->sum(fn ($o) => $o->amountInBase() ?? 0), 2),
                'won_count' => $won->count(),
                'open_total' => round($open->sum(fn ($o) => $o->amountInBase() ?? 0), 2),
                'open_count' => $open->count(),
                'pending_activities' => $client->activities->whereNull('completed_at')->count(),
            ],
            'duplicates' => array_values(array_filter(
                DuplicateFinder::find(['email' => $client->email, 'phone' => $client->phone, 'company_name' => $client->name]),
                // Its own record, its contacts and the leads it came from are not duplicates.
                fn ($d) => ! ($d['type'] === 'client' && $d['id'] === $client->id)
                    && $d['type'] !== 'contact'
                    && ! ($d['type'] === 'lead' && $client->leads->contains('id', $d['id'])),
            )),
            ...$this->options(),
        ]);
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $this->authorizeRecord($client, 'crm.edit');
        $client->update($this->validated($request));
        ActivityLogger::log('crm.client_updated', $client, "تعديل عميل {$client->name}", ActivityLogger::diff($client));

        return back()->with('success', 'تم حفظ التعديلات.');
    }

    public function destroy(Client $client): RedirectResponse
    {
        $this->authorizeRecord($client, 'crm.delete');
        $client->delete();
        ActivityLogger::log('crm.client_archived', $client, "أرشفة عميل {$client->name}");

        return redirect('/crm/clients')->with('success', 'تمت أرشفة العميل.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'legal_name' => ['nullable', 'string', 'max:190'],
            'type' => ['required', Rule::in(array_keys(config('crm.client_types')))],
            'industry' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'website' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:80'],
            'city' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:64'],
            'currency' => ['required', 'exists:currencies,code'],
            'source' => ['nullable', Rule::in(array_keys(config('crm.lead_sources')))],
            'owner_id' => ['nullable', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'task_prefix' => ['nullable', 'string', 'max:6', 'regex:/^[A-Za-z][A-Za-z0-9]*$/',
                Rule::unique('clients', 'task_prefix')->ignore($request->route('client')?->id)],
        ]);
        if (! empty($data['task_prefix'])) {
            $data['task_prefix'] = strtoupper($data['task_prefix']);
        } else {
            unset($data['task_prefix']); // an empty field keeps the current prefix
        }

        return $data;
    }
}

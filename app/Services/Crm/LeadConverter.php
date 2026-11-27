<?php

namespace App\Services\Crm;

use App\Models\Client;
use App\Models\Contact;
use App\Models\CrmActivity;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeadConverter
{
    /**
     * Turn a lead into a client (new or existing) + contact + optional opportunity.
     *
     * @param  array{client_id?: ?int, client?: array<string, mixed>, contact: array<string, mixed>, opportunity?: ?array<string, mixed>}  $data
     * @return array{client: Client, contact: Contact, opportunity: ?Opportunity}
     */
    public static function convert(Lead $lead, array $data): array
    {
        if ($lead->isConverted()) {
            throw ValidationException::withMessages(['lead' => 'تم تحويل هذا المحتمل سابقاً.']);
        }

        return DB::transaction(function () use ($lead, $data) {
            $userId = auth()->id();

            $client = ! empty($data['client_id'])
                ? Client::findOrFail($data['client_id'])
                : Client::create([
                    'name' => $lead->company_name ?: $lead->name,
                    'type' => $lead->company_name ? 'company' : 'individual',
                    'email' => $lead->email,
                    'phone' => $lead->phone,
                    'source' => $lead->source,
                    'currency' => $lead->currency,
                    'owner_id' => $lead->owner_id ?? $userId,
                    ...array_filter($data['client'] ?? [], fn ($v) => $v !== null && $v !== ''),
                    'created_by' => $userId,
                ]);

            $contact = Contact::create([
                'client_id' => $client->id,
                'name' => $lead->name,
                'job_title' => $lead->job_title,
                'email' => $lead->email,
                'phone' => $lead->phone,
                ...array_filter($data['contact'] ?? [], fn ($v) => $v !== null && $v !== ''),
                'is_primary' => ! $client->contacts()->exists(),
                'created_by' => $userId,
            ]);

            $opportunity = null;
            if (! empty($data['opportunity'])) {
                $o = $data['opportunity'];
                $opportunity = new Opportunity([
                    'client_id' => $client->id,
                    'contact_id' => $contact->id,
                    'lead_id' => $lead->id,
                    'title' => $o['title'] ?? ($lead->interest ?: "فرصة {$client->name}"),
                    'amount' => $o['amount'] ?? $lead->expected_value ?? 0,
                    'currency' => $o['currency'] ?? $lead->currency,
                    'expected_close_date' => $o['expected_close_date'] ?? null,
                    'owner_id' => $o['owner_id'] ?? $lead->owner_id ?? $userId,
                    'created_by' => $userId,
                ]);
                OpportunityStages::move($opportunity, $o['stage'] ?? 'discovery');
            }

            // The lead's history follows the new client.
            CrmActivity::where('subject_type', 'lead')->where('subject_id', $lead->id)
                ->update(['subject_type' => 'client', 'subject_id' => $client->id]);

            $lead->stage = Lead::CONVERTED;
            $lead->converted_at = now();
            $lead->client_id = $client->id;
            $lead->save();

            ActivityLogger::log('crm.lead_converted', $lead, "تحويل {$lead->name} إلى العميل {$client->name}", [
                'client_id' => $client->id,
                'contact_id' => $contact->id,
                'opportunity_id' => $opportunity?->id,
            ]);

            return ['client' => $client, 'contact' => $contact, 'opportunity' => $opportunity];
        });
    }
}

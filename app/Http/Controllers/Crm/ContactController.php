<?php

namespace App\Http\Controllers\Crm;

use App\Models\Client;
use App\Models\Contact;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ContactController extends CrmController
{
    public function store(Request $request, Client $client): RedirectResponse
    {
        $this->authorizeRecord($client, 'crm.edit');
        $data = $this->validated($request);
        $contact = $client->contacts()->create($data + [
            'created_by' => $request->user()->id,
            'is_primary' => ($data['is_primary'] ?? false) || ! $client->contacts()->exists(),
        ]);
        ActivityLogger::log('crm.contact_created', $client, "إضافة جهة اتصال {$contact->name}");

        return back()->with('success', 'تمت إضافة جهة الاتصال.');
    }

    public function update(Request $request, Client $client, Contact $contact): RedirectResponse
    {
        $this->authorizeRecord($client, 'crm.edit');
        abort_unless($contact->client_id === $client->id, 404);
        $contact->update($this->validated($request));
        ActivityLogger::log('crm.contact_updated', $client, "تعديل جهة اتصال {$contact->name}", ActivityLogger::diff($contact));

        return back()->with('success', 'تم حفظ جهة الاتصال.');
    }

    public function destroy(Client $client, Contact $contact): RedirectResponse
    {
        $this->authorizeRecord($client, 'crm.edit');
        abort_unless($contact->client_id === $client->id, 404);
        $contact->delete();
        ActivityLogger::log('crm.contact_archived', $client, "حذف جهة اتصال {$contact->name}");

        return back()->with('success', 'تم حذف جهة الاتصال.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'whatsapp' => ['nullable', 'string', 'max:32'],
            'is_primary' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}

<?php

namespace App\Services\Crm;

use App\Models\Client;
use App\Models\Contact;
use App\Models\Lead;

/**
 * Finds existing leads, clients and contacts that look like the same person or company.
 * Matches on email (case-insensitive), phone (last 9 digits) or exact company name.
 */
class DuplicateFinder
{
    /**
     * @param  array{email?: ?string, phone?: ?string, company_name?: ?string, name?: ?string}  $attrs
     * @return list<array{type: string, id: int, name: string, matched_on: list<string>, url: string}>
     */
    public static function find(array $attrs, ?int $ignoreLeadId = null): array
    {
        $email = mb_strtolower(trim((string) ($attrs['email'] ?? '')));
        $phone = Lead::normalizePhone($attrs['phone'] ?? null);
        $company = mb_strtolower(trim((string) ($attrs['company_name'] ?? '')));

        if ($email === '' && $phone === null && $company === '') {
            return [];
        }

        $out = [];
        $add = function (string $type, int $id, string $name, array $on, string $url) use (&$out) {
            $key = "$type:$id";
            $out[$key] ??= ['type' => $type, 'id' => $id, 'name' => $name, 'matched_on' => [], 'url' => $url];
            $out[$key]['matched_on'] = array_values(array_unique([...$out[$key]['matched_on'], ...$on]));
        };
        $matched = function ($row) use ($email, $phone) {
            $on = [];
            if ($email !== '' && mb_strtolower((string) $row->email) === $email) {
                $on[] = 'email';
            }
            if ($phone !== null && $row->phone_normalized === $phone) {
                $on[] = 'phone';
            }

            return $on;
        };

        $leads = Lead::query()->whereNull('merged_into_id')
            ->when($ignoreLeadId, fn ($q) => $q->whereKeyNot($ignoreLeadId))
            ->where(function ($q) use ($email, $phone, $company) {
                if ($email !== '') {
                    $q->orWhereRaw('LOWER(email) = ?', [$email]);
                }
                if ($phone !== null) {
                    $q->orWhere('phone_normalized', $phone);
                }
                if ($company !== '') {
                    $q->orWhereRaw('LOWER(TRIM(company_name)) = ?', [$company]);
                }
            })->limit(10)->get();
        foreach ($leads as $l) {
            $on = $matched($l);
            if ($company !== '' && mb_strtolower(trim((string) $l->company_name)) === $company) {
                $on[] = 'company';
            }
            $add('lead', $l->id, $l->displayName(), $on, "/crm/leads/{$l->id}");
        }

        $clients = Client::query()->where(function ($q) use ($email, $phone, $company) {
            if ($email !== '') {
                $q->orWhereRaw('LOWER(email) = ?', [$email]);
            }
            if ($phone !== null) {
                $q->orWhere('phone_normalized', $phone);
            }
            if ($company !== '') {
                $q->orWhereRaw('LOWER(TRIM(name)) = ?', [$company])->orWhereRaw('LOWER(TRIM(legal_name)) = ?', [$company]);
            }
        })->limit(10)->get();
        foreach ($clients as $c) {
            $on = $matched($c);
            if ($company !== '' && in_array($company, [mb_strtolower(trim((string) $c->name)), mb_strtolower(trim((string) $c->legal_name))], true)) {
                $on[] = 'company';
            }
            $add('client', $c->id, $c->name, $on, "/crm/clients/{$c->id}");
        }

        if ($email !== '' || $phone !== null) {
            $contacts = Contact::with('client:id,name')->where(function ($q) use ($email, $phone) {
                if ($email !== '') {
                    $q->orWhereRaw('LOWER(email) = ?', [$email]);
                }
                if ($phone !== null) {
                    $q->orWhere('phone_normalized', $phone);
                }
            })->limit(10)->get();
            foreach ($contacts as $c) {
                $add('contact', $c->id, $c->name.($c->client ? " ({$c->client->name})" : ''), $matched($c), "/crm/clients/{$c->client_id}");
            }
        }

        return array_values($out);
    }
}

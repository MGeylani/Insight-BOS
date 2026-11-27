<?php

namespace App\Services\Sales;

use App\Models\Contract;
use App\Models\ContractSchedule;
use App\Models\Setting;
use App\Services\Pdf\PdfRenderer;

/** Branded PDF of a contract, ready to sign. Same look as quotes; drafts and void contracts carry a watermark. */
class ContractPdf
{
    public static function filename(Contract $contract): string
    {
        return ($contract->number ?? "contract-draft-{$contract->id}").'.pdf';
    }

    public static function render(Contract $contract): string
    {
        $d = self::data($contract);
        $html = view('pdf.contract', ['d' => $d])->render();
        $co = $d['company'];
        $contact = implode(' · ', array_filter([$co['name'], $co['website'], $co['email'], $co['phone']]));

        return (new PdfRenderer)->render($html, [
            'title' => 'عقد '.($contract->number ?? '').' — '.$contract->title,
            'author' => $co['name'],
            'watermark' => match ($contract->status) {
                'draft' => 'مسودة',
                'cancelled' => 'ملغى',
                'terminated' => 'مفسوخ',
                default => null,
            },
            'footer' => PdfRenderer::footer($contact),
        ]);
    }

    /** @return array<string, mixed> */
    public static function data(Contract $contract): array
    {
        $contract->loadMissing('schedules', 'client', 'contact', 'owner', 'quote');
        $client = $contract->client;
        $company = [];
        foreach (['name', 'legal_name', 'email', 'phone', 'website', 'address', 'tax_number'] as $f) {
            $company[$f] = (string) Setting::get("company.$f", $f === 'name' ? 'Insight Media' : '');
        }
        $total = $contract->totalValue();
        $rows = $contract->schedules->where('status', '!=', 'cancelled');

        return [
            'logo' => resource_path('pdf/logo.png'),
            'company' => $company,
            'client' => [
                'name' => $client?->name ?? '—',
                'legal_name' => $client?->legal_name,
                'contact' => $contract->contact?->name,
                'phone' => $contract->contact?->phone ?: $client?->phone,
                'email' => $contract->contact?->email ?: $client?->email,
                'address' => implode('، ', array_filter([$client?->address, $client?->city, $client?->country])),
                'tax_number' => $client?->tax_number,
            ],
            'owner' => $contract->owner?->name,
            'contract' => [
                'number' => $contract->number,
                'type' => $contract->type,
                'type_label' => config("crm.contract_types.{$contract->type}"),
                'title' => $contract->title,
                'issue_date' => $contract->issue_date?->format('Y-m-d'),
                'start_date' => $contract->start_date?->format('Y-m-d'),
                'end_date' => $contract->end_date?->format('Y-m-d'),
                'currency' => $contract->currency,
                'value' => $total,
                'monthly_fee' => $contract->monthly_fee === null ? null : (float) $contract->monthly_fee,
                'term_months' => $contract->term_months,
                'billing_timing' => $contract->billing_timing ? config("crm.billing_timings.{$contract->billing_timing}") : null,
                'auto_renew' => $contract->auto_renew,
                'notice_days' => $contract->notice_days,
                'scope' => $contract->scope,
                'terms' => $contract->terms,
                'notes' => $contract->notes,
                'quote_number' => $contract->quote?->number,
                'signed_on' => $contract->signed_on?->format('Y-m-d'),
                'client_signatory' => $contract->client_signatory,
            ],
            'payments' => $rows->where('kind', 'payment')->values()->map(fn (ContractSchedule $s) => [
                'title' => $s->title,
                'details' => $s->details,
                'due_date' => $s->due_date?->format('Y-m-d'),
                'period_start' => $s->period_start?->format('Y-m-d'),
                'period_end' => $s->period_end?->format('Y-m-d'),
                'amount' => (float) $s->amount,
                'percent' => ! $contract->isRetainer() && $total > 0 ? rtrim(rtrim(number_format((float) $s->amount / $total * 100, 1, '.', ''), '0'), '.') : null,
            ])->all(),
            'milestones' => $rows->where('kind', 'milestone')->values()->map(fn (ContractSchedule $s) => [
                'title' => $s->title,
                'details' => $s->details,
                'due_date' => $s->due_date?->format('Y-m-d'),
            ])->all(),
            'payments_total' => $contract->scheduledTotal(),
        ];
    }
}

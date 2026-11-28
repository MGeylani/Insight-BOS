<?php

namespace App\Services\Sales;

use App\Models\Quote;
use App\Models\Setting;
use App\Services\Pdf\PdfRenderer;

/** Branded PDF of a quote (company logo, colours and Madani font). Drafts carry a watermark. */
class QuotePdf
{
    public static function filename(Quote $quote): string
    {
        return ($quote->number ?? "draft-{$quote->id}").'.pdf';
    }

    public static function render(Quote $quote): string
    {
        $d = self::data($quote);
        $html = view('pdf.quote', ['d' => $d])->render();
        $co = $d['company'];
        $contact = implode(' · ', array_filter([$co['name'], $co['website'], $co['email'], $co['phone']]));

        return (new PdfRenderer)->render($html, [
            'title' => 'عرض سعر '.($quote->number ?? '').' — '.$quote->title,
            'author' => $co['name'],
            'watermark' => match ($quote->displayStatus()) {
                'draft' => 'مسودة',
                'cancelled' => 'ملغى',
                default => null,
            },
            'footer' => PdfRenderer::footer($contact),
        ]);
    }

    /** Company block printed on every branded document. @return array<string, string> */
    public static function company(): array
    {
        $company = [];
        foreach (['name', 'legal_name', 'email', 'phone', 'website', 'address', 'tax_number'] as $f) {
            $company[$f] = (string) Setting::get("company.$f", $f === 'name' ? 'Insight Media' : '');
        }

        return $company;
    }

    /** @return array<string, mixed> */
    public static function data(Quote $quote): array
    {
        $quote->loadMissing('items', 'client', 'contact', 'owner');
        $client = $quote->client;
        $company = self::company();
        $label = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');

        return [
            'logo' => resource_path('pdf/logo.png'),
            'company' => $company,
            'client' => [
                'name' => $client?->name ?? '—',
                'legal_name' => $client?->legal_name,
                'contact' => $quote->contact?->name,
                'phone' => $quote->contact?->phone ?: $client?->phone,
                'email' => $quote->contact?->email ?: $client?->email,
                'address' => implode('، ', array_filter([$client?->address, $client?->city, $client?->country])),
                'tax_number' => $client?->tax_number,
            ],
            'owner' => $quote->owner?->name,
            'quote' => [
                'number' => $quote->number,
                'title' => $quote->title,
                'issue_date' => $quote->issue_date?->format('Y-m-d'),
                'valid_until' => $quote->valid_until?->format('Y-m-d'),
                'currency' => $quote->currency,
                'intro' => $quote->intro,
                'terms' => $quote->terms,
                'notes' => $quote->notes,
            ],
            'items' => $quote->items->map(fn ($it) => [
                'name' => $it->name,
                'details' => $it->details,
                'quantity' => $label($it->quantity),
                'unit' => $it->unit,
                'unit_price' => (float) $it->unit_price,
                'discount_percent' => (float) $it->discount_percent,
                'discount_percent_label' => $label($it->discount_percent),
                'tax_rate' => (float) $it->tax_rate,
                'tax_rate_label' => $label($it->tax_rate),
                'net' => $it->net(),
            ])->all(),
            'totals' => $quote->totals(),
        ];
    }
}

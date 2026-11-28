<?php

namespace App\Services\Finance;

use App\Models\Invoice;
use App\Services\Pdf\PdfRenderer;
use App\Services\Sales\QuotePdf;

/** Branded PDF of an invoice. Watermarks: draft «مسودة», cancelled «ملغاة», paid «مدفوعة». */
class InvoicePdf
{
    public static function filename(Invoice $invoice): string
    {
        return ($invoice->number ?? "draft-invoice-{$invoice->id}").'.pdf';
    }

    public static function render(Invoice $invoice): string
    {
        $d = self::data($invoice);
        $html = view('pdf.invoice', ['d' => $d])->render();
        $co = $d['company'];

        return (new PdfRenderer)->render($html, [
            'title' => 'فاتورة '.($invoice->number ?? '').' — '.$invoice->title,
            'author' => $co['name'],
            'watermark' => match ($invoice->displayStatus()) {
                'draft' => 'مسودة',
                'cancelled' => 'ملغاة',
                'paid' => 'مدفوعة',
                default => null,
            },
            'footer' => PdfRenderer::footer(implode(' · ', array_filter([$co['name'], $co['website'], $co['email'], $co['phone']]))),
        ]);
    }

    /** @return array<string, mixed> */
    public static function data(Invoice $invoice): array
    {
        $invoice->loadMissing('items', 'client', 'contact', 'contract:id,number', 'project:id,number');
        $client = $invoice->client;
        $label = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
        $totals = $invoice->totals();
        if (! $invoice->isDraft()) {
            $totals['total'] = $invoice->grandTotal();
        }
        $paid = $invoice->paidAmount();

        return [
            'logo' => resource_path('pdf/logo.png'),
            'company' => QuotePdf::company(),
            'client' => [
                'name' => $client?->name ?? '—',
                'legal_name' => $client?->legal_name,
                'contact' => $invoice->contact?->name,
                'phone' => $invoice->contact?->phone ?: $client?->phone,
                'email' => $invoice->contact?->email ?: $client?->email,
                'address' => implode('، ', array_filter([$client?->address, $client?->city, $client?->country])),
                'tax_number' => $client?->tax_number,
            ],
            'owner' => null,
            'invoice' => [
                'number' => $invoice->number,
                'title' => $invoice->title,
                'issue_date' => $invoice->issue_date?->format('Y-m-d'),
                'due_date' => $invoice->due_date?->format('Y-m-d'),
                'reference' => implode(' · ', array_filter([$invoice->contract?->number, $invoice->project?->number])),
                'currency' => $invoice->currency,
                'intro' => $invoice->intro,
                'terms' => $invoice->terms,
                'notes' => $invoice->notes,
                'payment_details' => $invoice->payment_details,
            ],
            'items' => $invoice->items->map(fn ($it) => [
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
            'totals' => $totals,
            'paid' => $paid,
            'balance' => round($totals['total'] - $paid, 2),
        ];
    }
}

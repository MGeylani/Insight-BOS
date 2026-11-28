<?php

namespace App\Services\Finance;

use App\Models\Payment;
use App\Services\Pdf\PdfRenderer;
use App\Services\Sales\QuotePdf;

/** Branded receipt voucher (سند قبض) or refund voucher (سند ردّ). Reversed vouchers carry a «معكوس» watermark. */
class ReceiptPdf
{
    public static function render(Payment $p): string
    {
        $p->loadMissing('client', 'account', 'allocations.invoice:id,number,currency', 'creator:id,name');
        $co = QuotePdf::company();
        $html = view('pdf.receipt', ['d' => [
            'logo' => resource_path('pdf/logo.png'),
            'company' => $co,
            'title' => $p->isReceipt() ? 'سند قبض' : 'سند ردّ مبلغ',
            'party_label' => $p->isReceipt() ? 'استلمنا من' : 'دفعنا إلى',
            'number' => $p->number,
            'date' => $p->date->format('Y-m-d'),
            'client' => $p->client?->name ?? '—',
            'amount' => (float) $p->amount,
            'currency' => $p->currency,
            'method' => config('finance.payment_methods')[$p->method] ?? $p->method,
            'account' => $p->account?->name,
            'reference' => $p->reference,
            'notes' => $p->notes,
            'allocations' => $p->allocations->map(fn ($a) => ['invoice' => $a->invoice?->number, 'amount' => (float) $a->amount, 'currency' => $a->invoice?->currency])->all(),
            'by' => $p->creator?->name,
        ]])->render();

        return (new PdfRenderer)->render($html, [
            'title' => $p->number,
            'author' => $co['name'],
            'watermark' => $p->isPosted() ? null : 'معكوس',
            'footer' => PdfRenderer::footer(implode(' · ', array_filter([$co['name'], $co['website'], $co['email'], $co['phone']]))),
        ]);
    }
}

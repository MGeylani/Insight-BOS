<?php

/*
| Finance vocabularies (phase 4). Amounts are DECIMAL(14,2); every money document keeps its
| currency and the exchange rate of its day (1 USD = fx_rate units).
*/

return [
    // Stored: draft | issued | cancelled. The rest are derived from payments and the due date.
    'invoice_statuses' => [
        'draft' => 'مسودة',
        'issued' => 'غير مدفوعة',
        'partial' => 'مدفوعة جزئياً',
        'paid' => 'مدفوعة',
        'overdue' => 'متأخرة',
        'cancelled' => 'ملغاة',
    ],

    // Days from issue to due date when nothing else is set (Settings → company data).
    'payment_days' => 14,

    // Retainer instalments due within this many days get a draft invoice automatically (finance:retainer-invoices).
    'retainer_lead_days' => 7,
];

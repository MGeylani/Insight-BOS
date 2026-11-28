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

    'account_types' => ['cash' => 'صندوق نقدي', 'bank' => 'حساب بنكي', 'wallet' => 'محفظة إلكترونية'],

    'payment_methods' => [
        'cash' => 'نقداً', 'transfer' => 'حوالة / تحويل بنكي', 'card' => 'بطاقة', 'cheque' => 'شيك', 'wallet' => 'محفظة إلكترونية', 'other' => 'أخرى',
    ],

    // Overdue reminders to the invoice owner on these days past due (finance:overdue, daily).
    'overdue_reminder_days' => [1, 7, 14, 30, 60],

    // Receivables aging buckets (days past due).
    'aging_buckets' => ['current' => 'غير مستحقة', '1_30' => '1–30 يوماً', '31_60' => '31–60', '61_90' => '61–90', '90_plus' => 'أكثر من 90'],

    // Expenses (step 3).
    'paid_by' => [
        'account' => 'دُفع من حساب الشركة', 'employee' => 'دفعه الموظف (يُعوَّض)', 'advance' => 'من عهدة/سلفة', 'credit' => 'فاتورة مورد (آجل)',
    ],
    'expense_statuses' => ['pending' => 'بانتظار الاعتماد', 'approved' => 'معتمد', 'rejected' => 'مرفوض', 'cancelled' => 'ملغى'],
    'settlement_labels' => [
        'unpaid' => 'غير مسدّدة', 'partial' => 'مسدّدة جزئياً', 'paid' => 'مسدّدة', 'overdue' => 'متأخرة',
        'to_reimburse' => 'بانتظار التعويض', 'reimbursed' => 'عُوِّض',
    ],
    'expense_category_kinds' => ['direct' => 'تكلفة مباشرة (أعمال العملاء)', 'overhead' => 'مصاريف عامة (تشغيل الوكالة)'],
    'expense_receipt_required' => true,
    'vendor_types' => ['company' => 'شركة', 'freelancer' => 'مستقل'],
    'vendor_payment_days' => 30,
    'advance_kinds' => ['petty_cash' => 'عهدة نثرية', 'salary_advance' => 'سلفة على الراتب'],

    // Days from issue to due date when nothing else is set (Settings → company data).
    'payment_days' => 14,

    // Retainer instalments due within this many days get a draft invoice automatically (finance:retainer-invoices).
    'retainer_lead_days' => 7,
];

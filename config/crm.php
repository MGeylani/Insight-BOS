<?php

/*
| CRM vocabularies. Keys are stored in the database; labels are shown in the UI.
| Opportunity stages carry a default win probability used for the weighted forecast.
*/

return [
    'lead_sources' => [
        'referral' => 'توصية',
        'website' => 'الموقع الإلكتروني',
        'social' => 'التواصل الاجتماعي',
        'ads' => 'حملة إعلانية',
        'whatsapp' => 'واتساب',
        'event' => 'معرض أو فعالية',
        'cold' => 'تواصل مباشر',
        'existing_client' => 'عميل حالي',
        'other' => 'أخرى',
    ],

    // Kanban columns, in order. `converted` is terminal and hidden from the board.
    'lead_stages' => [
        'new' => 'جديد',
        'contacted' => 'تم التواصل',
        'qualified' => 'مؤهَّل',
        'unqualified' => 'غير مؤهَّل',
    ],

    'lead_ratings' => [
        'hot' => 'ساخن',
        'warm' => 'دافئ',
        'cold' => 'بارد',
    ],

    'opportunity_stages' => [
        'discovery' => ['label' => 'اكتشاف الاحتياج', 'probability' => 10],
        'proposal' => ['label' => 'إعداد العرض', 'probability' => 30],
        'sent' => ['label' => 'العرض مُرسَل', 'probability' => 50],
        'negotiation' => ['label' => 'تفاوض', 'probability' => 75],
        'won' => ['label' => 'مكسوبة', 'probability' => 100],
        'lost' => ['label' => 'خاسرة', 'probability' => 0],
    ],

    'lost_reasons' => [
        'price' => 'السعر',
        'competitor' => 'اختار منافساً',
        'timing' => 'التوقيت غير مناسب',
        'budget' => 'لا توجد ميزانية',
        'no_response' => 'انقطع التواصل',
        'scope' => 'خارج نطاق خدماتنا',
        'other' => 'سبب آخر',
    ],

    'activity_types' => [
        'call' => 'مكالمة',
        'meeting' => 'اجتماع',
        'whatsapp' => 'واتساب',
        'email' => 'بريد',
        'note' => 'ملاحظة',
        'follow_up' => 'متابعة',
    ],

    'quote_statuses' => [
        'draft' => 'مسودة',
        'sent' => 'مُرسَل',
        'expired' => 'منتهي الصلاحية', // derived: sent and past its validity date
        'accepted' => 'مقبول',
        'rejected' => 'مرفوض',
        'cancelled' => 'ملغى',
    ],

    // Suggestions for the unit field of services and quote lines (free text is allowed).
    'service_units' => ['مشروع', 'شهر', 'منشور', 'فيديو', 'تصميم', 'ساعة', 'يوم', 'صفحة', 'قطعة', 'حملة'],

    'client_types' => [
        'company' => 'شركة',
        'organization' => 'منظمة / جمعية',
        'individual' => 'فرد',
    ],
];

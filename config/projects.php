<?php

/*
| Project vocabularies and templates. Keys are stored in the database; labels are shown in the UI.
|
| A template is picked by project type. Creating a project from a template adds its milestones
| (due = start date + offset_days) and its usual risks. Tasks arrive in phase 3a and will hang
| off these milestones.
*/

return [
    'types' => [
        'identity' => 'هوية بصرية',
        'campaign' => 'حملة إعلانية',
        'video' => 'إنتاج فيديو',
        'website' => 'موقع إلكتروني',
        'exhibition' => 'معرض أو فعالية',
        'social' => 'إدارة منصات التواصل',
        'other' => 'أخرى',
    ],

    // planning -> active -> completed, with on_hold / cancelled on the side. "overdue" is derived, never stored.
    'statuses' => [
        'planning' => 'تخطيط',
        'active' => 'قيد التنفيذ',
        'on_hold' => 'متوقف مؤقتاً',
        'completed' => 'مكتمل',
        'cancelled' => 'ملغى',
    ],

    'member_roles' => ['مدير حساب', 'مصمم', 'كاتب محتوى', 'مصور', 'مونتير', 'مطوّر', 'مسوّق رقمي', 'منسق فعاليات'],

    'milestone_statuses' => [
        'pending' => 'قيد العمل',
        'done' => 'منجزة',
    ],

    // Probability and impact are scored 1-3; the risk score is their product (1-9).
    'risk_levels' => [1 => 'منخفض', 2 => 'متوسط', 3 => 'مرتفع'],
    'risk_categories' => [
        'scope' => 'النطاق',
        'schedule' => 'الجدول الزمني',
        'budget' => 'الميزانية',
        'client' => 'العميل والموافقات',
        'resources' => 'الفريق والموارد',
        'technical' => 'تقني',
        'supplier' => 'الموردون',
        'other' => 'أخرى',
    ],
    'risk_statuses' => [
        'open' => 'قائم',
        'mitigated' => 'تمت معالجته',
        'occurred' => 'وقع فعلاً',
        'closed' => 'مغلق',
    ],

    'issue_severities' => [
        'low' => 'منخفضة',
        'medium' => 'متوسطة',
        'high' => 'عالية',
        'critical' => 'حرجة',
    ],
    'issue_statuses' => [
        'open' => 'مفتوحة',
        'in_progress' => 'قيد الحل',
        'resolved' => 'محلولة',
    ],

    'change_sources' => [
        'client' => 'طلب العميل',
        'internal' => 'داخلي',
    ],
    'change_statuses' => [
        'pending' => 'بانتظار الاعتماد',
        'approved' => 'معتمد',
        'rejected' => 'مرفوض',
    ],

    'templates' => [
        'identity' => [
            'milestones' => [
                ['title' => 'جلسة الموجز وجمع المتطلبات', 'offset_days' => 3],
                ['title' => 'البحث ولوحة المزاج', 'offset_days' => 7],
                ['title' => 'تقديم مفاهيم الشعار', 'offset_days' => 14],
                ['title' => 'جولة التعديلات', 'offset_days' => 21],
                ['title' => 'اعتماد الشعار النهائي', 'offset_days' => 25],
                ['title' => 'دليل الهوية والتطبيقات', 'offset_days' => 35],
                ['title' => 'تسليم الملفات المصدرية', 'offset_days' => 40],
            ],
            'risks' => [
                ['title' => 'تأخر العميل في الموافقات', 'category' => 'client', 'probability' => 3, 'impact' => 2, 'mitigation' => 'تحديد مواعيد مراجعة ثابتة في الجدول ومهلة رد مكتوبة.'],
                ['title' => 'جولات تعديل تتجاوز المتفق عليه', 'category' => 'scope', 'probability' => 2, 'impact' => 2, 'mitigation' => 'توثيق عدد الجولات في العقد، وما زاد يُسجّل طلب تغيير.'],
            ],
        ],
        'campaign' => [
            'milestones' => [
                ['title' => 'الموجز والأهداف ومؤشرات الأداء', 'offset_days' => 3],
                ['title' => 'الاستراتيجية والفكرة الإبداعية', 'offset_days' => 10],
                ['title' => 'إنتاج المواد الإعلانية', 'offset_days' => 20],
                ['title' => 'اعتماد المواد وإعداد الحملات', 'offset_days' => 24],
                ['title' => 'إطلاق الحملة', 'offset_days' => 25],
                ['title' => 'تقرير منتصف الحملة', 'offset_days' => 40],
                ['title' => 'التقرير الختامي', 'offset_days' => 60],
            ],
            'risks' => [
                ['title' => 'رفض الإعلانات من المنصة', 'category' => 'technical', 'probability' => 2, 'impact' => 2, 'mitigation' => 'مراجعة سياسات المنصة قبل الإنتاج وتجهيز نسخ بديلة.'],
                ['title' => 'تأخر شحن ميزانية الإعلانات', 'category' => 'budget', 'probability' => 2, 'impact' => 3, 'mitigation' => 'تأكيد طريقة الدفع والشحن قبل موعد الإطلاق بأسبوع.'],
            ],
        ],
        'video' => [
            'milestones' => [
                ['title' => 'الموجز والفكرة', 'offset_days' => 3],
                ['title' => 'السيناريو والقصة المصورة', 'offset_days' => 10],
                ['title' => 'التحضير: مواقع، ممثلون، معدات', 'offset_days' => 15],
                ['title' => 'يوم التصوير', 'offset_days' => 18],
                ['title' => 'المونتاج الأول', 'offset_days' => 25],
                ['title' => 'التعديلات والصوت والألوان', 'offset_days' => 30],
                ['title' => 'التسليم النهائي', 'offset_days' => 33],
            ],
            'risks' => [
                ['title' => 'تأجيل يوم التصوير (طقس، موقع، ممثل)', 'category' => 'schedule', 'probability' => 2, 'impact' => 3, 'mitigation' => 'حجز موعد بديل وتأكيد المواقع والممثلين قبل 48 ساعة.'],
                ['title' => 'عطل في المعدات', 'category' => 'resources', 'probability' => 1, 'impact' => 3, 'mitigation' => 'فحص المعدات قبل يوم وتوفير بطاريات وبطاقات احتياطية.'],
            ],
        ],
        'website' => [
            'milestones' => [
                ['title' => 'المتطلبات وخريطة الموقع', 'offset_days' => 5],
                ['title' => 'التصميم المبدئي (Wireframes)', 'offset_days' => 12],
                ['title' => 'اعتماد التصميم', 'offset_days' => 20],
                ['title' => 'التطوير', 'offset_days' => 40],
                ['title' => 'إدخال المحتوى', 'offset_days' => 45],
                ['title' => 'الاختبار والمراجعة', 'offset_days' => 50],
                ['title' => 'الإطلاق والتدريب', 'offset_days' => 55],
            ],
            'risks' => [
                ['title' => 'تأخر وصول المحتوى من العميل', 'category' => 'client', 'probability' => 3, 'impact' => 2, 'mitigation' => 'قائمة محتوى بمواعيد تسليم واضحة منذ البداية.'],
                ['title' => 'توسع النطاق أثناء التطوير', 'category' => 'scope', 'probability' => 2, 'impact' => 3, 'mitigation' => 'تجميد النطاق بعد اعتماد التصميم، وكل إضافة طلب تغيير.'],
            ],
        ],
        'exhibition' => [
            'milestones' => [
                ['title' => 'الموجز ومساحة الجناح', 'offset_days' => 3],
                ['title' => 'تصميم الجناح والمطبوعات', 'offset_days' => 12],
                ['title' => 'اعتماد التصاميم', 'offset_days' => 16],
                ['title' => 'طلبات الموردين والطباعة', 'offset_days' => 20],
                ['title' => 'اللوجستيات والشحن', 'offset_days' => 26],
                ['title' => 'التركيب', 'offset_days' => 29],
                ['title' => 'يوم الفعالية', 'offset_days' => 30],
                ['title' => 'الفك والتقرير الختامي', 'offset_days' => 33],
            ],
            'risks' => [
                ['title' => 'تأخر المورد أو المطبعة', 'category' => 'supplier', 'probability' => 2, 'impact' => 3, 'mitigation' => 'موعد تسليم داخلي قبل الموعد الحقيقي بثلاثة أيام، ومورد بديل.'],
                ['title' => 'مشكلات الشحن أو التخليص', 'category' => 'supplier', 'probability' => 2, 'impact' => 3, 'mitigation' => 'الشحن المبكر وتأكيد متطلبات الجهة المنظمة.'],
            ],
        ],
        'social' => [
            'milestones' => [
                ['title' => 'تحليل الحسابات والمنافسين', 'offset_days' => 5],
                ['title' => 'خطة المحتوى الشهرية', 'offset_days' => 10],
                ['title' => 'اعتماد الخطة والتصاميم', 'offset_days' => 14],
                ['title' => 'بدء النشر', 'offset_days' => 15],
                ['title' => 'التقرير الشهري', 'offset_days' => 30],
            ],
            'risks' => [
                ['title' => 'تأخر اعتماد المحتوى', 'category' => 'client', 'probability' => 3, 'impact' => 2, 'mitigation' => 'جدول اعتماد أسبوعي ومحتوى احتياطي جاهز.'],
            ],
        ],
    ],
];

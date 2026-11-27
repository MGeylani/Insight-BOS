<?php

/*
| Permission catalog. A permission key is "module.action". Each grant carries a scope:
|   own      - only records the user created or owns
|   assigned - records the user is assigned to (project member, task assignee...)
|   all      - every record in the module
| Job titles never grant access; only explicit grants (or a template applied then edited) do.
*/

return [
    'scopes' => [
        'own' => 'سجلاته فقط',
        'assigned' => 'المُسند إليه',
        'all' => 'الكل',
    ],

    'modules' => [
        'crm' => [
            'label' => 'العملاء والمبيعات',
            'actions' => ['view' => 'عرض', 'create' => 'إنشاء', 'edit' => 'تعديل', 'delete' => 'أرشفة', 'export' => 'تصدير', 'convert' => 'تحويل لعميل/مشروع'],
        ],
        'projects' => [
            'label' => 'المشاريع',
            'actions' => ['view' => 'عرض', 'create' => 'إنشاء', 'edit' => 'تعديل', 'delete' => 'أرشفة', 'manage_budget' => 'إدارة الميزانية', 'approve_changes' => 'اعتماد طلبات التغيير'],
        ],
        'tasks' => [
            'label' => 'المهام',
            'actions' => ['view' => 'عرض', 'create' => 'إنشاء', 'edit' => 'تعديل', 'delete' => 'أرشفة', 'assign' => 'إسناد', 'track_time' => 'تسجيل الوقت'],
        ],
        'finance' => [
            'label' => 'المالية',
            'actions' => ['view' => 'عرض', 'create' => 'إنشاء', 'edit' => 'تعديل', 'delete' => 'إلغاء', 'approve' => 'اعتماد', 'export' => 'تصدير'],
        ],
        'hr' => [
            'label' => 'الموارد البشرية',
            'actions' => ['view' => 'عرض', 'create' => 'إنشاء', 'edit' => 'تعديل', 'approve_leave' => 'اعتماد الإجازات', 'view_salaries' => 'عرض الرواتب والتكلفة'],
        ],
        'assets' => [
            'label' => 'الأصول',
            'actions' => ['view' => 'عرض', 'create' => 'إنشاء', 'edit' => 'تعديل', 'assign' => 'تسليم واستلام العهد', 'delete' => 'أرشفة'],
        ],
        'comms' => [
            'label' => 'التواصل الداخلي',
            'actions' => ['view' => 'استخدام الدردشة', 'announce' => 'نشر الإعلانات'],
        ],
        'reports' => [
            'label' => 'التقارير',
            'actions' => ['view' => 'عرض', 'export' => 'تصدير'],
        ],
        'users' => [
            'label' => 'المستخدمون والصلاحيات',
            'actions' => ['view' => 'عرض', 'manage' => 'إدارة'],
        ],
        'settings' => [
            'label' => 'إعدادات النظام',
            'actions' => ['manage' => 'إدارة الإعدادات', 'exchange_rates' => 'إدخال أسعار الصرف', 'storage' => 'ربط التخزين السحابي'],
        ],
        'audit' => [
            'label' => 'سجل التدقيق',
            'actions' => ['view' => 'عرض'],
        ],
    ],
];

<?php

/*
| Task vocabularies. Keys are stored in the database; labels are shown in the UI.
| Status schemes themselves live in the database (status_schemes / task_statuses); the templates
| below are only what TaskStatusSeeder creates the first time.
*/

return [
    'max_depth' => 2,

    // Default working days for capacity (0 = Sunday … 6 = Saturday): Monday to Friday. Per person in users.work_days.
    'work_days' => [1, 2, 3, 4, 5], // 0 = task, 1 = subtask, 2 = sub-subtask (three levels)

    'priorities' => [
        'urgent' => 'عاجلة',
        'high' => 'عالية',
        'normal' => 'عادية',
        'low' => 'منخفضة',
    ],

    // Every status belongs to one group. done and closed both count as complete.
    'status_groups' => [
        'not_started' => 'لم تبدأ',
        'active' => 'نشطة',
        'done' => 'مكتملة',
        'closed' => 'مغلقة',
    ],

    // Task types created by TaskSeeder (once, by name). The first is the default.
    'types' => [
        ['name' => 'مهمة', 'icon' => 'circle-check', 'color' => '#0082F6', 'is_milestone' => false],
        ['name' => 'معلم', 'icon' => 'diamond', 'color' => '#481C4E', 'is_milestone' => true],
        ['name' => 'مخرَج للعميل', 'icon' => 'package', 'color' => '#00027C', 'is_milestone' => false],
        ['name' => 'جلسة تصوير', 'icon' => 'camera', 'color' => '#b45309', 'is_milestone' => false],
        ['name' => 'منشور', 'icon' => 'megaphone', 'color' => '#864690', 'is_milestone' => false],
        ['name' => 'اجتماع', 'icon' => 'users', 'color' => '#047857', 'is_milestone' => false],
    ],

    'internal_client' => [
        'name' => 'Insight Media',
        'prefix' => 'INS',
    ],

    // Lists created for a project that has no template.
    'default_project_lists' => ['المهام'],

    'scheme_templates' => [
        'agency' => [
            'name' => 'قالب الوكالة',
            'default' => true,
            'statuses' => [
                ['name' => 'موجز', 'color' => '#94a3b8', 'group' => 'not_started'],
                ['name' => 'قيد التنفيذ', 'color' => '#0082F6', 'group' => 'active'],
                ['name' => 'مراجعة داخلية', 'color' => '#864690', 'group' => 'active'],
                ['name' => 'بانتظار العميل', 'color' => '#f59e0b', 'group' => 'active'],
                ['name' => 'تعديلات', 'color' => '#ef4444', 'group' => 'active'],
                ['name' => 'معتمد', 'color' => '#10b981', 'group' => 'done'],
                ['name' => 'منشور', 'color' => '#00027C', 'group' => 'closed'],
            ],
        ],
        'simple' => [
            'name' => 'بسيط',
            'default' => false,
            'statuses' => [
                ['name' => 'للعمل', 'color' => '#94a3b8', 'group' => 'not_started'],
                ['name' => 'قيد التنفيذ', 'color' => '#0082F6', 'group' => 'active'],
                ['name' => 'منجزة', 'color' => '#10b981', 'group' => 'done'],
            ],
        ],
    ],
];

import {
    LayoutDashboard, Users, Briefcase, ListChecks, Wallet, UserRound, Package, MessagesSquare, BarChart3,
    ShieldCheck, ScrollText, Building2, Coins, Tags, Hash, Cloud, KeyRound, SquareKanban, Sun, Inbox, Gauge, ClipboardCheck, FileInput,
} from '@lucide/vue';

// `match` widens the active state to a whole section (e.g. every /crm page).
// `soon: true` items are planned modules (later phases) shown greyed out.
// Guests (clients sharing a project) see only the items marked `guest: true`.
export const navigation = [
    {
        label: null,
        items: [
            { label: 'لوحة القيادة', href: '/dashboard', icon: LayoutDashboard },
            { label: 'عملي', href: '/my-work', match: '/my-work', icon: Sun, perm: 'tasks.view', guest: true },
            { label: 'الوارد', href: '/inbox', icon: Inbox, badge: 'notifications_unread', guest: true },
        ],
    },
    {
        label: 'العمل',
        items: [
            { label: 'العملاء والمبيعات', href: '/crm/leads', match: '/crm', icon: Users, perm: 'crm.view' },
            { label: 'المشاريع', href: '/projects', match: '/projects', icon: Briefcase, perm: 'projects.view' },
            { label: 'المهام', href: '/tasks', icon: ListChecks, perm: 'tasks.view', guest: true },
            { label: 'ضغط العمل', href: '/tasks/workload', icon: Gauge, perm: 'tasks.view' },
            { label: 'نماذج الاستقبال', href: '/tasks/forms', icon: FileInput, perm: 'tasks.view' },
            { label: 'جداول الساعات', href: '/tasks/timesheets', icon: ClipboardCheck, perm: 'tasks.approve_time' },
            { label: 'المالية', href: '/finance/invoices', match: '/finance', icon: Wallet, perm: 'finance.view' },
            { label: 'الموارد البشرية', icon: UserRound, perm: 'hr.view', soon: true },
            { label: 'الأصول', icon: Package, perm: 'assets.view', soon: true },
            { label: 'التواصل', icon: MessagesSquare, perm: 'comms.view', soon: true },
            { label: 'التقارير', icon: BarChart3, perm: 'reports.view', soon: true },
        ],
    },
    {
        label: 'الإدارة',
        items: [
            { label: 'المستخدمون', href: '/admin/users', icon: ShieldCheck, perm: 'users.view' },
            { label: 'قوالب الصلاحيات', href: '/admin/roles', icon: KeyRound, perm: 'users.manage' },
            { label: 'سجل التدقيق', href: '/admin/activity', icon: ScrollText, perm: 'audit.view' },
        ],
    },
    {
        label: 'الإعدادات',
        items: [
            { label: 'بيانات الشركة', href: '/settings/company', icon: Building2, perm: 'settings.manage' },
            { label: 'العملات وأسعار الصرف', href: '/settings/currencies', icon: Coins, perm: 'settings.exchange_rates' },
            { label: 'تصنيفات الخدمات والضريبة', href: '/settings/service-categories', icon: Tags, perm: 'settings.manage' },
            { label: 'ترقيم المستندات', href: '/settings/numbering', icon: Hash, perm: 'settings.manage' },
            { label: 'التخزين السحابي', href: '/settings/storage', icon: Cloud, perm: 'settings.storage' },
            { label: 'المهام: الحالات والوسوم', href: '/settings/tasks', icon: SquareKanban, perm: 'settings.manage' },
        ],
    },
];

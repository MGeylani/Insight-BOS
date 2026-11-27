import {
    LayoutDashboard, Users, Briefcase, ListChecks, Wallet, UserRound, Package, MessagesSquare, BarChart3,
    ShieldCheck, ScrollText, Building2, Coins, Tags, Hash, Cloud, KeyRound,
} from '@lucide/vue';

// `match` widens the active state to a whole section (e.g. every /crm page).
// `soon: true` items are planned modules (later phases) shown greyed out.
export const navigation = [
    {
        label: null,
        items: [{ label: 'لوحة القيادة', href: '/dashboard', icon: LayoutDashboard }],
    },
    {
        label: 'العمل',
        items: [
            { label: 'العملاء والمبيعات', href: '/crm/leads', match: '/crm', icon: Users, perm: 'crm.view' },
            { label: 'المشاريع', icon: Briefcase, perm: 'projects.view', soon: true },
            { label: 'المهام', icon: ListChecks, perm: 'tasks.view', soon: true },
            { label: 'المالية', icon: Wallet, perm: 'finance.view', soon: true },
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
        ],
    },
];

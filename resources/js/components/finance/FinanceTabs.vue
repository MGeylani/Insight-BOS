<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { FileText, HandCoins, Clock4, Landmark, ReceiptText, Truck, Wallet2, Undo2, PiggyBank, LayoutDashboard, BarChart3 } from '@lucide/vue';
import { useAuth } from '@/lib/auth';

// Finance sections, shown by permission.
const page = usePage();
const { perms, can } = useAuth();
const all = (perm) => Boolean(perms.value['*']) || perms.value[perm] === 'all';
const tabs = computed(() => [
    all('finance.view') && { label: 'اللوحة', href: '/finance', icon: LayoutDashboard, exact: true },
    can('finance.view') && { label: 'الفواتير', href: '/finance/invoices', icon: FileText },
    can('finance.view') && { label: 'المقبوضات', href: '/finance/payments', icon: HandCoins },
    can('finance.view') && { label: 'الذمم المدينة', href: '/finance/receivables', icon: Clock4 },
    (can('finance.expense') || can('finance.view') || can('finance.approve')) && { label: 'المصروفات', href: '/finance/expenses', icon: ReceiptText },
    can('finance.view') && { label: 'الموردون', href: '/finance/vendors', icon: Truck },
    (can('finance.expense') || can('finance.view')) && { label: 'السلف والعهد', href: '/finance/advances', icon: Wallet2 },
    (can('finance.view') || can('projects.manage_budget')) && { label: 'الميزانيات', href: '/finance/budgets', icon: PiggyBank },
    all('finance.view') && { label: 'التعويضات', href: '/finance/reimbursements', icon: Undo2 },
    all('finance.view') && { label: 'الصناديق والحسابات', href: '/finance/accounts', icon: Landmark },
    all('finance.view') && { label: 'التقارير', href: '/finance/reports', icon: BarChart3 },
].filter(Boolean));
const active = (href) => (href === '/finance'
    ? page.url === '/finance' || page.url.startsWith('/finance?')
    : page.url === href || page.url.startsWith(href + '/') || page.url.startsWith(href + '?'));
</script>

<template>
    <nav v-if="tabs.length > 1" class="mb-6 flex gap-1 overflow-x-auto rounded-2xl border border-slate-200/80 bg-white p-1">
        <Link v-for="t in tabs" :key="t.href" :href="t.href" class="flex items-center gap-2 whitespace-nowrap rounded-xl px-4 py-2 text-sm transition"
            :class="active(t.href) ? 'bg-navy text-white font-semibold' : 'text-slate-600 hover:bg-slate-100'">
            <component :is="t.icon" class="size-4" /> {{ t.label }}
        </Link>
    </nav>
</template>

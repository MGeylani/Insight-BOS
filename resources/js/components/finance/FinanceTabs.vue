<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { FileText, HandCoins, Clock4, Landmark } from '@lucide/vue';
import { useAuth } from '@/lib/auth';

// Grows with phase 4: payments, accounts, expenses, budgets, reports.
const page = usePage();
const { perms } = useAuth();
const all = (perm) => Boolean(perms.value['*']) || perms.value[perm] === 'all';
const tabs = [
    { label: 'الفواتير', href: '/finance/invoices', icon: FileText },
    { label: 'المقبوضات', href: '/finance/payments', icon: HandCoins },
    { label: 'الذمم المدينة', href: '/finance/receivables', icon: Clock4 },
    ...(all('finance.view') ? [{ label: 'الصناديق والحسابات', href: '/finance/accounts', icon: Landmark }] : []),
];
const active = (href) => page.url === href || page.url.startsWith(href + '/') || page.url.startsWith(href + '?');
</script>

<template>
    <nav class="mb-6 flex gap-1 overflow-x-auto rounded-2xl border border-slate-200/80 bg-white p-1">
        <Link v-for="t in tabs" :key="t.href" :href="t.href" class="flex items-center gap-2 whitespace-nowrap rounded-xl px-4 py-2 text-sm transition"
            :class="active(t.href) ? 'bg-navy text-white font-semibold' : 'text-slate-600 hover:bg-slate-100'">
            <component :is="t.icon" class="size-4" /> {{ t.label }}
        </Link>
    </nav>
</template>

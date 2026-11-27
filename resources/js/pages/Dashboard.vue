<script setup>
import { Link } from '@inertiajs/vue3';
import { Users, Briefcase, ListChecks, Wallet, Coins, ArrowLeft } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Badge from '@/components/Badge.vue';
import { useAuth } from '@/lib/auth';
import { formatDate, formatNumber } from '@/lib/format';

defineProps({ stats: Object, rates: Array });
const { user, can } = useAuth();

const hour = new Date().getHours();
const greeting = hour < 12 ? 'صباح الخير' : 'مساء الخير';

const upcoming = [
    { icon: Users, label: 'العملاء والمبيعات', phase: 'المرحلة 2' },
    { icon: Briefcase, label: 'المشاريع والعقود', phase: 'المرحلة 2' },
    { icon: ListChecks, label: 'المهام بمستوى ClickUp', phase: 'المرحلة 3' },
    { icon: Wallet, label: 'المالية والفواتير', phase: 'المرحلة 4' },
];
</script>

<template>
    <AppLayout title="لوحة القيادة">
        <section class="brand-gradient relative mb-8 overflow-hidden rounded-3xl px-8 py-10 text-white">
            <img src="/images/logo-mark.svg" alt="" class="absolute -start-10 -bottom-16 w-72 opacity-10 brightness-0 invert" />
            <p class="text-sm text-sky">{{ greeting }}</p>
            <h1 class="mt-1 text-3xl font-bold">{{ user?.name }}</h1>
            <p class="mt-2 max-w-xl text-white/75">هذه النسخة الأولى من منظومة Insight BOS: الحسابات والصلاحيات والإعدادات الأساسية جاهزة، والموديولات تُضاف تباعاً.</p>
        </section>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="card p-6">
                <p class="text-sm text-slate-500">المستخدمون النشطون</p>
                <p class="mt-2 text-4xl font-bold text-navy num">{{ stats.users }}</p>
                <Link v-if="can('users.view')" href="/admin/users" class="mt-4 inline-flex items-center gap-1 text-sm font-medium text-brand hover:underline">إدارة المستخدمين <ArrowLeft class="size-4" /></Link>
            </div>

            <div class="card p-6 lg:col-span-2">
                <div class="flex items-center justify-between">
                    <p class="flex items-center gap-2 font-semibold text-slate-800"><Coins class="size-5 text-brand" /> أسعار الصرف مقابل الدولار</p>
                    <Link v-if="can('settings.exchange_rates')" href="/settings/currencies" class="text-sm font-medium text-brand hover:underline">تحديث</Link>
                </div>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div v-for="r in rates" :key="r.code" class="rounded-xl border border-slate-200 p-4">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-slate-600">{{ r.name }}</span>
                            <Badge :tone="r.rate === null ? 'red' : r.stale ? 'amber' : 'green'">{{ r.rate === null ? 'غير مُدخل' : r.stale ? 'ليس سعر اليوم' : 'محدّث' }}</Badge>
                        </div>
                        <p class="mt-2 text-2xl font-bold text-navy"><span class="num">{{ formatNumber(r.rate, 4) }}</span> <span class="text-sm font-medium text-slate-400">{{ r.code }}</span></p>
                        <p class="text-xs text-slate-400">{{ r.date ? formatDate(r.date) : '—' }}</p>
                    </div>
                </div>
            </div>
        </div>

        <h2 class="mt-10 mb-4 font-semibold text-slate-700">قادم في المراحل التالية</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div v-for="m in upcoming" :key="m.label" class="card flex items-center gap-4 p-5">
                <span class="flex size-11 items-center justify-center rounded-xl bg-navy-50 text-navy"><component :is="m.icon" class="size-5" /></span>
                <div>
                    <p class="font-medium text-slate-800">{{ m.label }}</p>
                    <p class="text-xs text-slate-400">{{ m.phase }}</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { reactive, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import FinanceTabs from '@/components/finance/FinanceTabs.vue';
import { formatDate, formatNumber } from '@/lib/format';
import { money } from '@/lib/crm';

const props = defineProps({ account: Object, statement: Object, range: Object, types: Object });
const r = reactive({ ...props.range });
watch(r, () => router.get(`/finance/accounts/${props.account.id}`, { ...r }, { preserveState: true, replace: true }));
</script>

<template>
    <AppLayout :title="account.name">
        <PageHeader :title="account.name" :subtitle="`${types[account.type]} · ${account.currency} · الرصيد الحالي ${money(account.balance, account.currency)}`">
            <template #actions><Link href="/finance/accounts" class="inline-flex items-center gap-1 text-sm text-brand hover:underline"><ArrowRight class="size-4" /> كل الحسابات</Link></template>
        </PageHeader>
        <FinanceTabs />

        <div class="mb-6 grid gap-4 sm:grid-cols-4">
            <div class="card p-4"><p class="text-xs text-slate-500">رصيد أول الفترة</p><p class="num mt-1 text-lg font-bold">{{ formatNumber(statement.opening) }}</p></div>
            <div class="card p-4"><p class="text-xs text-slate-500">الداخل</p><p class="num mt-1 text-lg font-bold text-emerald-700">{{ formatNumber(statement.in) }}</p></div>
            <div class="card p-4"><p class="text-xs text-slate-500">الخارج</p><p class="num mt-1 text-lg font-bold text-red-600">{{ formatNumber(statement.out) }}</p></div>
            <div class="card p-4"><p class="text-xs text-slate-500">رصيد آخر الفترة</p><p class="num mt-1 text-lg font-bold text-navy">{{ formatNumber(statement.closing) }}</p></div>
        </div>

        <div class="card overflow-hidden">
            <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 p-4 text-sm">
                <span class="text-slate-500">من</span><input v-model="r.from" type="date" dir="ltr" class="field-input w-auto" />
                <span class="text-slate-500">إلى</span><input v-model="r.to" type="date" dir="ltr" class="field-input w-auto" />
            </div>
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead><tr><th>التاريخ</th><th>البيان</th><th>المرجع</th><th>داخل</th><th>خارج</th><th>الرصيد</th></tr></thead>
                    <tbody>
                        <tr class="bg-slate-50 font-semibold"><td></td><td>الرصيد الافتتاحي</td><td></td><td></td><td></td><td class="num">{{ formatNumber(statement.opening) }}</td></tr>
                        <tr v-for="(m, i) in statement.rows" :key="i">
                            <td class="whitespace-nowrap text-slate-600">{{ formatDate(m.date) }}</td>
                            <td>{{ m.label }}</td>
                            <td><Link v-if="m.url" :href="m.url" class="num text-brand hover:underline">{{ m.ref }}</Link><span v-else class="text-slate-500">{{ m.ref }}</span></td>
                            <td class="num text-emerald-700">{{ m.amount > 0 ? formatNumber(m.amount) : '' }}</td>
                            <td class="num text-red-600">{{ m.amount < 0 ? formatNumber(-m.amount) : '' }}</td>
                            <td class="num font-semibold">{{ formatNumber(m.balance) }}</td>
                        </tr>
                        <tr v-if="!statement.rows.length"><td colspan="6" class="py-8 text-center text-slate-500">لا حركات في هذه الفترة.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

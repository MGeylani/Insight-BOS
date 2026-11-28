<script setup>
import { reactive, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Plus, Search } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Badge from '@/components/Badge.vue';
import Pagination from '@/components/Pagination.vue';
import EmptyState from '@/components/EmptyState.vue';
import FinanceTabs from '@/components/finance/FinanceTabs.vue';
import { formatDate } from '@/lib/format';
import { money, usd } from '@/lib/crm';

const props = defineProps({ payments: Object, summary: Object, filters: Object, accounts: Array, clients: Array, methods: Object, can: Object });
const f = reactive({ search: '', kind: '', account: '', client: '', from: '', to: '', ...props.filters });
let t;
watch(f, () => {
    clearTimeout(t);
    t = setTimeout(() => router.get('/finance/payments', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300);
});
</script>

<template>
    <AppLayout title="المقبوضات">
        <PageHeader title="المالية" subtitle="سندات القبض من العملاء وسندات ردّ المبالغ.">
            <template #actions><Btn v-if="can.create" href="/finance/payments/create"><Plus class="size-4" /> سند قبض</Btn></template>
        </PageHeader>
        <FinanceTabs />

        <div class="mb-6 grid gap-4 sm:grid-cols-2">
            <div class="card p-5"><p class="text-xs text-slate-500">مقبوض هذا الشهر</p><p class="num mt-1 text-2xl font-bold text-emerald-700">{{ usd(summary.received_month_usd) }}</p></div>
            <div class="card p-5"><p class="text-xs text-slate-500">مردود هذا الشهر</p><p class="num mt-1 text-2xl font-bold text-slate-700">{{ usd(summary.refunded_month_usd) }}</p></div>
        </div>

        <div class="card overflow-hidden">
            <div class="flex flex-wrap gap-2 border-b border-slate-100 p-4">
                <div class="relative min-w-56 flex-1 sm:max-w-xs">
                    <Search class="absolute inset-y-0 start-3 my-auto size-4 text-slate-400" />
                    <input v-model="f.search" type="search" placeholder="رقم السند، المرجع، العميل" class="field-input ps-9" />
                </div>
                <select v-model="f.kind" class="field-input w-auto"><option value="">قبض وردّ</option><option value="receipt">قبض</option><option value="refund">ردّ</option></select>
                <select v-model="f.account" class="field-input w-auto"><option value="">كل الحسابات</option><option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }} ({{ a.currency }})</option></select>
                <select v-model="f.client" class="field-input w-auto max-w-xs"><option value="">كل العملاء</option><option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                <input v-model="f.from" type="date" dir="ltr" class="field-input w-auto" title="من" />
                <input v-model="f.to" type="date" dir="ltr" class="field-input w-auto" title="إلى" />
            </div>
            <div class="overflow-x-auto">
                <table v-if="payments.data.length" class="tbl">
                    <thead><tr><th>الرقم</th><th>التاريخ</th><th>العميل</th><th>المبلغ</th><th>الحساب</th><th>الطريقة</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="p in payments.data" :key="p.id" class="cursor-pointer" :class="{ 'opacity-50 line-through': p.status === 'reversed' }" @click="router.visit(`/finance/payments/${p.id}`)">
                            <td class="num whitespace-nowrap font-semibold text-navy">{{ p.number }}</td>
                            <td class="whitespace-nowrap text-slate-600">{{ formatDate(p.date) }}</td>
                            <td class="font-medium text-slate-800">{{ p.client?.name }}</td>
                            <td class="whitespace-nowrap"><p class="num font-semibold" :class="p.kind === 'refund' ? 'text-red-600' : 'text-emerald-700'">{{ p.kind === 'refund' ? '−' : '' }}{{ money(p.amount, p.currency) }}</p><p v-if="p.currency !== 'USD'" class="num text-xs text-slate-500">{{ usd(p.amount_usd) }}</p></td>
                            <td class="text-slate-600">{{ p.account?.name }}</td>
                            <td class="text-slate-600">{{ methods[p.method] }}</td>
                            <td><Badge v-if="p.kind === 'refund'" tone="amber">ردّ</Badge><Badge v-if="p.status === 'reversed'" tone="slate">معكوس</Badge></td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-else title="لا توجد سندات" text="سجّل دفعة العميل من هنا أو من صفحة الفاتورة مباشرة.">
                    <Btn v-if="can.create" href="/finance/payments/create"><Plus class="size-4" /> سند قبض</Btn>
                </EmptyState>
            </div>
            <Pagination :links="payments.links" />
        </div>
    </AppLayout>
</template>

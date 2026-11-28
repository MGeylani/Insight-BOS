<script setup>
import { reactive, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { FileSpreadsheet, Plus, AlertTriangle } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Badge from '@/components/Badge.vue';
import FinanceTabs from '@/components/finance/FinanceTabs.vue';
import { formatDate, formatNumber } from '@/lib/format';
import { money, usd } from '@/lib/crm';
import { useAuth } from '@/lib/auth';

const props = defineProps({ client: Object, statement: Object, range: Object, can: Object });
const { can: has } = useAuth();
const r = reactive({ ...props.range });
watch(r, () => router.get(`/finance/statements/${props.client.id}`, { ...r }, { preserveState: true, replace: true }));
const typeLabel = { invoice: 'فاتورة', receipt: 'قبض', refund: 'ردّ' };
const typeTone = { invoice: 'navy', receipt: 'green', refund: 'amber' };
</script>

<template>
    <AppLayout :title="`كشف حساب ${client.name}`">
        <PageHeader :title="`كشف حساب ${client.name}`" subtitle="الفواتير الصادرة (مدين) والمقبوضات (دائن)، بالدولار بسعر صرف كل مستند.">
            <template #actions>
                <a v-if="has('finance.export')" :href="`/finance/statements/${client.id}/xlsx?from=${r.from}&to=${r.to}`" class="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"><FileSpreadsheet class="size-4" /> Excel</a>
                <Btn v-if="can.receive && statement.open_invoices.length" :href="`/finance/payments/create?client_id=${client.id}`"><Plus class="size-4" /> سند قبض</Btn>
            </template>
        </PageHeader>
        <FinanceTabs />

        <div class="mb-6 grid gap-4 sm:grid-cols-4">
            <div class="card p-4"><p class="text-xs text-slate-500">رصيد أول الفترة</p><p class="num mt-1 text-lg font-bold">{{ usd(statement.opening) }}</p></div>
            <div class="card p-4"><p class="text-xs text-slate-500">مدين</p><p class="num mt-1 text-lg font-bold text-navy">{{ usd(statement.debit) }}</p></div>
            <div class="card p-4"><p class="text-xs text-slate-500">دائن</p><p class="num mt-1 text-lg font-bold text-emerald-700">{{ usd(statement.credit) }}</p></div>
            <div class="card p-4"><p class="text-xs text-slate-500">الرصيد المستحق</p><p class="num mt-1 text-lg font-bold" :class="statement.closing > 0 ? 'text-red-600' : 'text-emerald-700'">{{ usd(statement.closing) }}</p></div>
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="card overflow-hidden xl:col-span-2">
                <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 p-4 text-sm">
                    <span class="text-slate-500">من</span><input v-model="r.from" type="date" dir="ltr" class="field-input w-auto" />
                    <span class="text-slate-500">إلى</span><input v-model="r.to" type="date" dir="ltr" class="field-input w-auto" />
                </div>
                <div class="overflow-x-auto">
                    <table class="tbl">
                        <thead><tr><th>التاريخ</th><th>المستند</th><th>البيان</th><th>المبلغ</th><th>مدين $</th><th>دائن $</th><th>الرصيد $</th></tr></thead>
                        <tbody>
                            <tr class="bg-slate-50 font-semibold"><td></td><td></td><td>الرصيد الافتتاحي</td><td></td><td></td><td></td><td class="num">{{ formatNumber(statement.opening) }}</td></tr>
                            <tr v-for="row in statement.rows" :key="row.type + row.id">
                                <td class="whitespace-nowrap text-slate-600">{{ formatDate(row.date) }}</td>
                                <td class="whitespace-nowrap"><Badge :tone="typeTone[row.type]">{{ typeLabel[row.type] }}</Badge> <Link :href="row.url" class="num text-brand hover:underline">{{ row.number }}</Link></td>
                                <td>{{ row.label }}</td>
                                <td class="num whitespace-nowrap text-slate-600">{{ money(row.amount, row.currency) }}</td>
                                <td class="num">{{ row.debit_usd ? formatNumber(row.debit_usd) : '' }}</td>
                                <td class="num text-emerald-700">{{ row.credit_usd ? formatNumber(row.credit_usd) : '' }}</td>
                                <td class="num font-semibold">{{ formatNumber(row.balance_usd) }}</td>
                            </tr>
                            <tr v-if="!statement.rows.length"><td colspan="7" class="py-8 text-center text-slate-500">لا مستندات في هذه الفترة.</td></tr>
                            <tr class="bg-slate-50 font-bold"><td></td><td></td><td>الرصيد الختامي</td><td></td><td class="num">{{ formatNumber(statement.debit) }}</td><td class="num">{{ formatNumber(statement.credit) }}</td><td class="num text-navy">{{ formatNumber(statement.closing) }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="space-y-6">
                <div class="card p-5">
                    <h3 class="mb-3 font-semibold text-navy">الفواتير المفتوحة</h3>
                    <ul v-if="statement.open_invoices.length" class="divide-y divide-slate-100 text-sm">
                        <li v-for="i in statement.open_invoices" :key="i.id" class="flex items-center justify-between gap-2 py-2">
                            <div>
                                <Link :href="`/finance/invoices/${i.id}`" class="num font-semibold text-brand hover:underline">{{ i.number }}</Link>
                                <p class="text-xs" :class="i.overdue ? 'font-semibold text-red-600' : 'text-slate-500'"><AlertTriangle v-if="i.overdue" class="inline size-3" /> يستحق {{ formatDate(i.due_date) }}</p>
                            </div>
                            <p class="num font-semibold">{{ money(i.balance, i.currency) }}</p>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-slate-500">لا فواتير مفتوحة.</p>
                </div>
                <div v-if="statement.credits.length" class="card p-5">
                    <h3 class="mb-3 font-semibold text-navy">رصيد للعميل غير مسوّى</h3>
                    <ul class="space-y-2 text-sm">
                        <li v-for="c in statement.credits" :key="c.id" class="flex justify-between"><Link :href="`/finance/payments/${c.id}`" class="num text-brand hover:underline">{{ c.number }}</Link><span class="num font-semibold text-emerald-700">{{ money(c.unallocated, c.currency) }}</span></li>
                    </ul>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

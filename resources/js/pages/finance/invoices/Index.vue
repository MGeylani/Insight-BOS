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
import { invoiceStatusTone } from '@/lib/finance';

const props = defineProps({ invoices: Object, summary: Object, filters: Object, statuses: Object, clients: Array, can: Object });
const f = reactive({ search: props.filters.search ?? '', status: props.filters.status ?? '', client: props.filters.client ?? '' });
let t;
watch(f, () => {
    clearTimeout(t);
    t = setTimeout(() => router.get('/finance/invoices', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300);
});
const stat = (s) => { f.status = f.status === s ? '' : s; };
</script>

<template>
    <AppLayout title="الفواتير">
        <PageHeader title="المالية" subtitle="الفواتير: مسودات، وصادرة بانتظار السداد، ومتأخرة.">
            <template #actions><Btn v-if="can.create" href="/finance/invoices/create"><Plus class="size-4" /> فاتورة</Btn></template>
        </PageHeader>
        <FinanceTabs />

        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <button class="card p-5 text-start transition hover:ring-2 hover:ring-brand/20" @click="stat('issued')">
                <p class="text-xs text-slate-500">مستحق من العملاء</p>
                <p class="num mt-1 text-2xl font-bold text-navy">{{ usd(summary.open_usd) }}</p>
                <p class="mt-1 text-xs text-slate-500"><span class="num">{{ summary.open_count }}</span> فاتورة غير مسددة</p>
            </button>
            <button class="card p-5 text-start transition hover:ring-2 hover:ring-red-200" @click="stat('overdue')">
                <p class="text-xs text-slate-500">متأخر</p>
                <p class="num mt-1 text-2xl font-bold text-red-600">{{ usd(summary.overdue_usd) }}</p>
                <p class="mt-1 text-xs text-slate-500"><span class="num">{{ summary.overdue_count }}</span> فاتورة</p>
            </button>
            <div class="card p-5">
                <p class="text-xs text-slate-500">صدر هذا الشهر</p>
                <p class="num mt-1 text-2xl font-bold text-emerald-700">{{ usd(summary.issued_month_usd) }}</p>
            </div>
            <button class="card p-5 text-start transition hover:ring-2 hover:ring-brand/20" @click="stat('draft')">
                <p class="text-xs text-slate-500">مسودات</p>
                <p class="num mt-1 text-2xl font-bold text-slate-700">{{ summary.drafts }}</p>
            </button>
        </div>

        <div class="card overflow-hidden">
            <div class="flex flex-wrap gap-2 border-b border-slate-100 p-4">
                <div class="relative min-w-56 flex-1 sm:max-w-sm">
                    <Search class="absolute inset-y-0 start-3 my-auto size-4 text-slate-400" />
                    <input v-model="f.search" type="search" placeholder="رقم الفاتورة، العنوان، العميل" class="field-input ps-9" />
                </div>
                <select v-model="f.status" class="field-input w-auto"><option value="">كل الحالات</option><option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option></select>
                <select v-model="f.client" class="field-input w-auto max-w-xs"><option value="">كل العملاء</option><option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option></select>
            </div>
            <div class="overflow-x-auto">
                <table v-if="invoices.data.length" class="tbl">
                    <thead><tr><th>الرقم</th><th>الفاتورة</th><th>الحالة</th><th>الإجمالي</th><th>المتبقي</th><th>الإصدار</th><th>الاستحقاق</th></tr></thead>
                    <tbody>
                        <tr v-for="i in invoices.data" :key="i.id" class="cursor-pointer" @click="router.visit(`/finance/invoices/${i.id}`)">
                            <td class="num whitespace-nowrap font-semibold text-navy">{{ i.number ?? '—' }}</td>
                            <td><p class="font-medium text-slate-800">{{ i.title }}</p><p class="text-xs text-slate-500">{{ i.client?.name }}</p></td>
                            <td><Badge :tone="invoiceStatusTone[i.status]">{{ statuses[i.status] }}</Badge></td>
                            <td class="whitespace-nowrap"><p class="num font-semibold">{{ money(i.total, i.currency) }}</p><p v-if="i.currency !== 'USD'" class="num text-xs text-slate-500">{{ usd(i.total_usd) }}</p></td>
                            <td class="num whitespace-nowrap" :class="i.balance > 0 ? 'text-slate-800' : 'text-slate-400'">{{ i.status === 'draft' || i.status === 'cancelled' ? '—' : money(i.balance, i.currency) }}</td>
                            <td class="whitespace-nowrap text-slate-600">{{ formatDate(i.issue_date) }}</td>
                            <td class="whitespace-nowrap" :class="i.status === 'overdue' ? 'font-semibold text-red-600' : 'text-slate-600'">{{ formatDate(i.due_date) }}</td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-else title="لا توجد فواتير" text="أنشئ فاتورة من هنا، أو من دفعة عقد، أو من عرض سعر مقبول.">
                    <Btn v-if="can.create" href="/finance/invoices/create"><Plus class="size-4" /> فاتورة</Btn>
                </EmptyState>
            </div>
            <Pagination :links="invoices.links" />
        </div>
    </AppLayout>
</template>

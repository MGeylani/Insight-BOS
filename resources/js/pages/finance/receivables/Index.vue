<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import EmptyState from '@/components/EmptyState.vue';
import FinanceTabs from '@/components/finance/FinanceTabs.vue';
import { formatNumber } from '@/lib/format';
import { usd } from '@/lib/crm';
import { useAuth } from '@/lib/auth';

const props = defineProps({ rows: Array, totals: Object, buckets: Object });
const { perms } = useAuth();
const canStatement = computed(() => Boolean(perms.value['*']) || perms.value['finance.view'] === 'all');
const tone = { current: 'text-slate-700', '1_30': 'text-amber-600', '31_60': 'text-orange-600', '61_90': 'text-red-600', '90_plus': 'text-red-700 font-bold' };
const cell = (v) => (v ? formatNumber(v) : '—');
</script>

<template>
    <AppLayout title="الذمم المدينة">
        <PageHeader title="المالية" subtitle="ما يستحق على العملاء موزعاً حسب أيام التأخر، بالدولار بسعر صرف كل فاتورة." />
        <FinanceTabs />

        <div class="mb-6 grid gap-4 sm:grid-cols-3 xl:grid-cols-6">
            <div class="card p-4"><p class="text-xs text-slate-500">إجمالي المستحق</p><p class="num mt-1 text-lg font-bold text-navy">{{ usd(totals.total) }}</p></div>
            <div v-for="(l, k) in buckets" :key="k" class="card p-4"><p class="text-xs text-slate-500">{{ l }}</p><p class="num mt-1 text-lg font-bold" :class="tone[k]">{{ usd(totals[k] ?? 0) }}</p></div>
        </div>

        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table v-if="rows.length" class="tbl">
                    <thead><tr><th>العميل</th><th>الفواتير</th><th v-for="(l, k) in buckets" :key="k">{{ l }}</th><th>المجموع</th></tr></thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.client?.id">
                            <td class="font-medium">
                                <Link v-if="canStatement" :href="`/finance/statements/${r.client.id}`" class="text-brand hover:underline">{{ r.client?.name }}</Link>
                                <Link v-else :href="`/finance/invoices?client=${r.client.id}&status=open`" class="text-brand hover:underline">{{ r.client?.name }}</Link>
                            </td>
                            <td class="num text-slate-600">{{ r.invoices }}</td>
                            <td v-for="(l, k) in buckets" :key="k" class="num" :class="r[k] ? tone[k] : 'text-slate-300'">{{ cell(r[k]) }}</td>
                            <td class="num font-bold text-navy">{{ formatNumber(r.total) }}</td>
                        </tr>
                        <tr class="bg-slate-50 font-bold">
                            <td>المجموع</td><td></td>
                            <td v-for="(l, k) in buckets" :key="k" class="num">{{ cell(totals[k]) }}</td>
                            <td class="num text-navy">{{ formatNumber(totals.total) }}</td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-else title="لا ذمم مفتوحة" text="كل الفواتير الصادرة مدفوعة بالكامل." />
            </div>
        </div>
    </AppLayout>
</template>

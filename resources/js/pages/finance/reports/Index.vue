<script setup>
import { computed, reactive, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { FileSpreadsheet } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import FinanceTabs from '@/components/finance/FinanceTabs.vue';
import { formatNumber } from '@/lib/format';
import { usd } from '@/lib/crm';

const props = defineProps({ params: Object, report: Object, groups: Object, accounts: Array, canExport: Boolean });
const p = reactive({ ...props.params, account: props.params.account ?? '' });
const query = computed(() => Object.fromEntries(Object.entries(p).filter(([k, v]) => v !== '' && v !== null && !(k === 'group' && p.type !== 'profit') && !(k === 'account' && p.type !== 'cashflow'))));
watch(p, () => router.get('/finance/reports', query.value, { preserveState: true, replace: true }));
const xlsx = computed(() => '/finance/reports/xlsx?' + new URLSearchParams(query.value).toString());
const singular = { project: 'المشروع', client: 'العميل', service: 'الخدمة', employee: 'الموظف' };
const types = { pnl: 'الأرباح والخسائر', cashflow: 'التدفق النقدي', profit: 'الربحية' };
const presets = {
    'هذا الشهر': () => [new Date(new Date().getFullYear(), new Date().getMonth(), 1), new Date()],
    'هذا الربع': () => { const n = new Date(); return [new Date(n.getFullYear(), Math.floor(n.getMonth() / 3) * 3, 1), n]; },
    'هذه السنة': () => [new Date(new Date().getFullYear(), 0, 1), new Date()],
    'آخر 12 شهراً': () => { const n = new Date(); return [new Date(n.getFullYear(), n.getMonth() - 11, 1), n]; },
};
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const preset = (fn) => { const [a, b] = fn(); Object.assign(p, { from: iso(a), to: iso(b) }); };
const monthName = (ym) => new Intl.DateTimeFormat('ar', { month: 'short', year: '2-digit' }).format(new Date(ym + '-01'));
const n = (v) => (v ? formatNumber(v) : '—');
const r = computed(() => props.report);
</script>

<template>
    <AppLayout title="تقارير المالية" wide>
        <PageHeader title="تقارير المالية" subtitle="كل المبالغ بالدولار بسعر صرف كل مستند (الفاتورة يوم إصدارها، المصروف والدفعة يوم تاريخهما).">
            <template #actions>
                <a v-if="canExport" :href="xlsx" class="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"><FileSpreadsheet class="size-4" /> Excel</a>
            </template>
        </PageHeader>
        <FinanceTabs />

        <div class="card mb-6 flex flex-wrap items-center gap-2 p-4">
            <div class="flex gap-1 rounded-xl bg-slate-100 p-1">
                <button v-for="(l, k) in types" :key="k" class="rounded-lg px-3 py-1.5 text-sm" :class="p.type === k ? 'bg-white font-semibold text-navy shadow-sm' : 'text-slate-600'" @click="p.type = k">{{ l }}</button>
            </div>
            <select v-if="p.type === 'profit'" v-model="p.group" class="field-input w-auto"><option v-for="(l, k) in groups" :key="k" :value="k">حسب {{ l }}</option></select>
            <select v-if="p.type === 'cashflow'" v-model="p.account" class="field-input w-auto"><option value="">كل الحسابات</option><option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }} ({{ a.currency }})</option></select>
            <input v-model="p.from" type="date" dir="ltr" class="field-input w-auto" />
            <span class="text-slate-400">–</span>
            <input v-model="p.to" type="date" dir="ltr" class="field-input w-auto" />
            <button v-for="(fn, l) in presets" :key="l" class="rounded-lg px-2 py-1 text-xs text-brand hover:bg-brand/5" @click="preset(fn)">{{ l }}</button>
        </div>

        <!-- Profit & loss / cash flow: rows × months -->
        <div v-if="p.type !== 'profit'" class="card overflow-x-auto">
            <table class="tbl text-sm">
                <thead><tr><th class="sticky start-0 z-10 min-w-48 bg-white">البند</th><th v-for="m in r.months" :key="m" class="whitespace-nowrap text-center">{{ monthName(m) }}</th><th class="text-center">المجموع</th></tr></thead>
                <tbody v-if="p.type === 'pnl'">
                    <tr class="bg-sky/10"><td :colspan="r.months.length + 2" class="font-semibold text-navy">الإيرادات</td></tr>
                    <tr v-for="row in r.revenue" :key="'r' + row.label"><td class="sticky start-0 bg-white">{{ row.label }}</td><td v-for="m in r.months" :key="m" class="num text-center">{{ n(row.values[m]) }}</td><td class="num text-center font-semibold">{{ n(row.total) }}</td></tr>
                    <tr class="font-bold"><td class="sticky start-0 bg-white">مجموع الإيرادات</td><td v-for="m in r.months" :key="m" class="num text-center">{{ n(r.totals.revenue.values[m]) }}</td><td class="num text-center">{{ n(r.totals.revenue.total) }}</td></tr>
                    <tr class="bg-sky/10"><td :colspan="r.months.length + 2" class="font-semibold text-navy">التكاليف المباشرة</td></tr>
                    <tr v-for="row in r.direct" :key="'d' + row.label"><td class="sticky start-0 bg-white">{{ row.label }}</td><td v-for="m in r.months" :key="m" class="num text-center">{{ n(row.values[m]) }}</td><td class="num text-center font-semibold">{{ n(row.total) }}</td></tr>
                    <tr class="bg-slate-50 font-bold"><td class="sticky start-0 bg-slate-50">مجمل الربح</td><td v-for="m in r.months" :key="m" class="num text-center" :class="r.totals.gross.values[m] < 0 ? 'text-red-600' : ''">{{ n(r.totals.gross.values[m]) }}</td><td class="num text-center">{{ n(r.totals.gross.total) }}</td></tr>
                    <tr class="bg-sky/10"><td :colspan="r.months.length + 2" class="font-semibold text-navy">المصاريف العامة</td></tr>
                    <tr v-for="row in r.overhead" :key="'o' + row.label"><td class="sticky start-0 bg-white">{{ row.label }}</td><td v-for="m in r.months" :key="m" class="num text-center">{{ n(row.values[m]) }}</td><td class="num text-center font-semibold">{{ n(row.total) }}</td></tr>
                    <tr class="bg-emerald-50 font-bold"><td class="sticky start-0 bg-emerald-50">صافي الربح</td><td v-for="m in r.months" :key="m" class="num text-center" :class="r.totals.net.values[m] < 0 ? 'text-red-600' : 'text-emerald-800'">{{ n(r.totals.net.values[m]) }}</td><td class="num text-center">{{ n(r.totals.net.total) }}</td></tr>
                    <tr class="text-xs text-slate-500"><td class="sticky start-0 bg-white">للعلم: تكلفة الساعات المسجلة</td><td v-for="m in r.months" :key="m" class="num text-center">{{ n(r.totals.labor.values[m]) }}</td><td class="num text-center">{{ n(r.totals.labor.total) }}</td></tr>
                </tbody>
                <tbody v-else>
                    <tr class="font-semibold"><td class="sticky start-0 bg-white">رصيد أول الفترة</td><td :colspan="r.months.length"></td><td class="num text-center">{{ formatNumber(r.opening) }}</td></tr>
                    <tr class="bg-sky/10"><td :colspan="r.months.length + 2" class="font-semibold text-navy">الداخل</td></tr>
                    <tr v-for="row in r.in" :key="'i' + row.kind"><td class="sticky start-0 bg-white">{{ row.label }}</td><td v-for="m in r.months" :key="m" class="num text-center text-emerald-700">{{ n(row.values[m]) }}</td><td class="num text-center font-semibold">{{ n(row.total) }}</td></tr>
                    <tr class="font-bold"><td class="sticky start-0 bg-white">مجموع الداخل</td><td v-for="m in r.months" :key="m" class="num text-center">{{ n(r.totals.in.values[m]) }}</td><td class="num text-center">{{ n(r.totals.in.total) }}</td></tr>
                    <tr class="bg-sky/10"><td :colspan="r.months.length + 2" class="font-semibold text-navy">الخارج</td></tr>
                    <tr v-for="row in r.out" :key="'o' + row.kind"><td class="sticky start-0 bg-white">{{ row.label }}</td><td v-for="m in r.months" :key="m" class="num text-center text-red-600">{{ n(row.values[m]) }}</td><td class="num text-center font-semibold">{{ n(row.total) }}</td></tr>
                    <tr class="font-bold"><td class="sticky start-0 bg-white">مجموع الخارج</td><td v-for="m in r.months" :key="m" class="num text-center">{{ n(r.totals.out.values[m]) }}</td><td class="num text-center">{{ n(r.totals.out.total) }}</td></tr>
                    <tr class="bg-slate-50 font-bold"><td class="sticky start-0 bg-slate-50">صافي التدفق</td><td v-for="m in r.months" :key="m" class="num text-center" :class="r.totals.net.values[m] < 0 ? 'text-red-600' : 'text-emerald-700'">{{ n(r.totals.net.values[m]) }}</td><td class="num text-center">{{ n(r.totals.net.total) }}</td></tr>
                    <tr class="font-semibold"><td class="sticky start-0 bg-white">رصيد آخر الفترة</td><td :colspan="r.months.length"></td><td class="num text-center">{{ formatNumber(r.closing) }}</td></tr>
                </tbody>
            </table>
            <p v-if="p.type === 'cashflow' && !p.account" class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">التحويلات بين حسابات الشركة لا تظهر هنا لأنها لا تُدخل ولا تُخرج مالاً. الرصيدان بسعر صرف يوم كل منهما.</p>
        </div>

        <!-- Profitability -->
        <div v-else class="card overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>{{ singular[p.group] }}</th><th>الإيراد</th><th>المصروفات</th><th>الساعات</th><th>تكلفة الساعات</th><th>الربح</th><th>الهامش</th></tr></thead>
                <tbody>
                    <tr v-for="row in r.rows" :key="row.id">
                        <td class="font-medium"><Link v-if="row.url" :href="row.url" class="text-brand hover:underline">{{ row.label }}</Link><span v-else>{{ row.label }}</span></td>
                        <td class="num">{{ n(row.revenue) }}</td>
                        <td class="num">{{ n(row.expenses) }}</td>
                        <td class="num text-slate-600">{{ row.minutes ? formatNumber(row.minutes / 60, 1) : '—' }}<span v-if="row.billable_minutes !== undefined && row.minutes" class="block text-xs">فوترة {{ Math.round((row.billable_minutes / row.minutes) * 100) }}%</span></td>
                        <td class="num">{{ n(row.labor) }}</td>
                        <td class="num font-semibold" :class="row.profit < 0 ? 'text-red-600' : 'text-emerald-700'">{{ formatNumber(row.profit) }}</td>
                        <td class="num" :class="row.margin !== null && row.margin < 0 ? 'text-red-600' : ''">{{ row.margin === null ? '—' : formatNumber(row.margin, 1) + '%' }}</td>
                    </tr>
                    <tr v-if="!r.rows.length"><td colspan="7" class="py-8 text-center text-slate-500">لا بيانات في هذه الفترة.</td></tr>
                    <tr v-else class="bg-slate-50 font-bold"><td>المجموع</td><td class="num">{{ n(r.totals.revenue) }}</td><td class="num">{{ n(r.totals.expenses) }}</td><td class="num">{{ formatNumber(r.totals.minutes / 60, 1) }}</td><td class="num">{{ n(r.totals.labor) }}</td><td class="num">{{ formatNumber(r.totals.profit) }}</td><td></td></tr>
                </tbody>
            </table>
            <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
                <template v-if="p.group === 'service'">تكاليف كل مشروع تُوزَّع على الخدمات التي فوترها بنسبة إيراد كل خدمة؛ تكاليف المشاريع بلا إيراد في الفترة لا تظهر هنا.</template>
                <template v-else-if="p.group === 'employee'">إيراد الموظف = إيراد كل مشروع عمل عليه × نسبة ساعاته من ساعات المشروع في الفترة. لا تشمل المصروفات.</template>
                <template v-else>الإيراد = الفواتير الصادرة في الفترة صافية من الضريبة؛ التكاليف = المصروفات المعتمدة وتكلفة الساعات المسجلة في الفترة.</template>
            </p>
        </div>
    </AppLayout>
</template>

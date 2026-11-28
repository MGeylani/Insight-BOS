<script setup>
import { router, Link } from '@inertiajs/vue3';
import { Pencil, Trash2, AlertTriangle } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import FinanceTabs from '@/components/finance/FinanceTabs.vue';
import BudgetBar from '@/components/finance/BudgetBar.vue';
import { formatDate, formatNumber } from '@/lib/format';
import { money, usd } from '@/lib/crm';

const props = defineProps({ budget: Object, report: Object, expenses: Array, can: Object });
const destroy = () => confirm('حذف الميزانية؟ لا تتأثر المصروفات.') && router.delete(`/finance/budgets/${props.budget.id}`);
</script>

<template>
    <AppLayout :title="budget.name">
        <PageHeader :title="budget.name" :subtitle="`${budget.target} · ${formatDate(budget.period_start)} – ${formatDate(budget.period_end)}${budget.owner ? ' · ' + budget.owner.name : ''}`">
            <template #actions>
                <Btn v-if="budget.project" variant="secondary" :href="`/projects/${budget.project.id}`">المشروع</Btn>
                <Btn v-if="can.edit" variant="secondary" :href="`/finance/budgets/${budget.id}/edit`"><Pencil class="size-4" /> تعديل</Btn>
                <Btn v-if="can.edit" variant="ghost" @click="destroy"><Trash2 class="size-4" /></Btn>
            </template>
        </PageHeader>
        <FinanceTabs />

        <div class="mb-6 grid gap-4 sm:grid-cols-4">
            <div class="card p-4"><p class="text-xs text-slate-500">المخطط</p><p class="num mt-1 text-lg font-bold">{{ usd(report.planned) }}</p></div>
            <div class="card p-4"><p class="text-xs text-slate-500">الفعلي</p><p class="num mt-1 text-lg font-bold" :class="report.actual > report.planned ? 'text-red-600' : 'text-navy'">{{ usd(report.actual) }}</p></div>
            <div class="card p-4"><p class="text-xs text-slate-500">المتبقي</p><p class="num mt-1 text-lg font-bold" :class="report.variance < 0 ? 'text-red-600' : 'text-emerald-700'">{{ usd(report.variance) }}</p></div>
            <div class="card p-4"><p class="text-xs text-slate-500">مضى من الفترة</p><p class="num mt-1 text-lg font-bold">{{ formatNumber(report.elapsed, 0) }}%</p></div>
        </div>

        <div class="card mb-6 overflow-hidden">
            <table class="tbl">
                <thead><tr><th>البند</th><th>المخطط</th><th>الفعلي</th><th>المتبقي</th><th class="w-1/3">النسبة</th></tr></thead>
                <tbody>
                    <tr v-for="l in report.lines" :key="l.id">
                        <td class="font-medium">{{ l.kind === 'labor' ? 'ساعات العمل' : l.category?.name }}</td>
                        <td class="num">{{ formatNumber(l.planned) }}</td>
                        <td class="num font-semibold">{{ formatNumber(l.actual) }}</td>
                        <td class="num" :class="l.variance < 0 ? 'font-semibold text-red-600' : 'text-slate-600'">{{ formatNumber(l.variance) }}</td>
                        <td><BudgetBar :percent="l.percent" :alert="budget.alert_percent" /></td>
                    </tr>
                </tbody>
            </table>
            <p v-if="report.unbudgeted" class="flex items-center gap-2 border-t border-amber-100 bg-amber-50 px-5 py-3 text-sm text-amber-900"><AlertTriangle class="size-4" /> {{ usd(report.unbudgeted) }} صُرفت على تصنيفات ليست في الميزانية.</p>
        </div>

        <div class="card overflow-hidden">
            <h3 class="border-b border-slate-100 px-5 py-3 font-semibold text-navy">المصروفات المعتمدة في الفترة</h3>
            <table class="tbl">
                <thead><tr><th>الرقم</th><th>التاريخ</th><th>البيان</th><th>التصنيف</th><th>المبلغ</th></tr></thead>
                <tbody>
                    <tr v-for="e in expenses" :key="e.id" class="cursor-pointer" @click="router.visit(`/finance/expenses/${e.id}`)">
                        <td class="num font-semibold text-navy">{{ e.number }}</td><td class="text-slate-600">{{ formatDate(e.date) }}</td>
                        <td>{{ e.description }}</td><td class="text-slate-600">{{ e.category?.name }}</td>
                        <td class="num">{{ money(e.amount, e.currency) }}<span v-if="e.currency !== 'USD'" class="block text-xs text-slate-500">{{ usd(e.amount_usd) }}</span></td>
                    </tr>
                    <tr v-if="!expenses.length"><td colspan="5" class="py-8 text-center text-slate-500">لا مصروفات معتمدة بعد.</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

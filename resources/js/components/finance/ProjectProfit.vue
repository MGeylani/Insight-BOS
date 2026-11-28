<script setup>
// Project money at a glance: revenue (invoiced, net of tax) vs expenses and labor, plus its budgets.
import { Link } from '@inertiajs/vue3';
import { TrendingUp, Plus } from '@lucide/vue';
import BudgetBar from '@/components/finance/BudgetBar.vue';
import { usd } from '@/lib/crm';
import { formatNumber } from '@/lib/format';

defineProps({ profit: Object, budgets: { type: Array, default: () => [] }, projectId: Number, canBudget: Boolean, canExpense: Boolean });
</script>

<template>
    <div class="card p-5">
        <div class="mb-3 flex items-center gap-2">
            <TrendingUp class="size-4 text-slate-400" />
            <h3 class="me-auto font-semibold text-navy">الربحية</h3>
            <Link v-if="canExpense" :href="`/finance/expenses/create?project_id=${projectId}`" class="flex items-center gap-1 text-xs text-brand hover:underline"><Plus class="size-3.5" /> مصروف</Link>
        </div>
        <dl class="space-y-2 text-sm">
            <div class="flex justify-between"><dt class="text-slate-500">الإيراد (المفوتر صافياً)</dt><dd class="num font-semibold">{{ usd(profit.revenue) }}</dd></div>
            <div class="flex justify-between text-xs"><dt class="text-slate-500">المحصّل · المتبقي</dt><dd class="num">{{ usd(profit.collected) }} · {{ usd(profit.outstanding) }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">المصروفات</dt><dd class="num text-red-600">− {{ usd(profit.expenses) }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">تكلفة الساعات</dt><dd class="num text-red-600">− {{ usd(profit.labor) }}</dd></div>
            <div class="flex justify-between rounded-xl px-3 py-2 font-bold" :class="profit.profit >= 0 ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-700'">
                <dt>الربح</dt><dd class="num">{{ usd(profit.profit) }}<span v-if="profit.margin !== null" class="ms-1 text-xs font-semibold">({{ formatNumber(profit.margin, 1) }}%)</span></dd>
            </div>
            <p v-if="profit.value" class="text-xs text-slate-500">قيمة المشروع المتفق عليها {{ usd(profit.value) }}؛ فوتر منها {{ formatNumber((profit.invoiced / profit.value) * 100, 0) }}%.</p>
            <p v-if="profit.uncosted_minutes" class="text-xs text-amber-700">بعض الساعات بلا تكلفة ساعة للموظف، فالتكلفة أقل من الحقيقة.</p>
        </dl>
        <ul v-if="profit.by_category.length" class="mt-3 space-y-1 border-t border-slate-100 pt-3 text-xs text-slate-600">
            <li v-for="c in profit.by_category" :key="c.name" class="flex justify-between"><span>{{ c.name }}</span><span class="num">{{ usd(c.usd) }}</span></li>
        </ul>
        <div class="mt-4 border-t border-slate-100 pt-3">
            <div class="mb-2 flex items-center justify-between"><p class="text-xs font-semibold text-slate-600">الميزانيات</p>
                <Link v-if="canBudget" :href="`/finance/budgets/create?project_id=${projectId}`" class="text-xs text-brand hover:underline">+ ميزانية</Link></div>
            <Link v-for="b in budgets" :key="b.id" :href="`/finance/budgets/${b.id}`" class="mb-2 block rounded-lg p-1 hover:bg-slate-50">
                <p class="mb-1 flex justify-between text-xs"><span class="font-medium text-slate-700">{{ b.name }}</span><span class="num text-slate-500">{{ usd(b.actual) }} / {{ usd(b.planned) }}</span></p>
                <BudgetBar :percent="b.percent" :alert="b.alert_percent" />
            </Link>
            <p v-if="!budgets.length" class="text-xs text-slate-400">لا ميزانية لهذا المشروع.</p>
        </div>
    </div>
</template>

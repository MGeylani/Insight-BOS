<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Landmark, Clock4, Truck, Undo2, Wallet2, ReceiptText, TrendingUp, TrendingDown, BarChart3 } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import FinanceTabs from '@/components/finance/FinanceTabs.vue';
import BudgetBar from '@/components/finance/BudgetBar.vue';
import { money, usd } from '@/lib/crm';

const props = defineProps({ data: Object });
const d = computed(() => props.data);
const max = computed(() => Math.max(1, ...d.value.trend.flatMap((t) => [t.revenue, t.cost])));
const monthName = (ym) => new Intl.DateTimeFormat('ar', { month: 'short' }).format(new Date(ym + '-01'));
const change = (now, before) => (before ? Math.round(((now - before) / Math.abs(before)) * 100) : null);
const revChange = computed(() => change(d.value.month.revenue, d.value.month.last_revenue));
</script>

<template>
    <AppLayout title="المالية">
        <PageHeader title="المالية" subtitle="الأرصدة والمستحقات وأداء الأشهر الأخيرة بالدولار.">
            <template #actions><Btn variant="secondary" href="/finance/reports"><BarChart3 class="size-4" /> التقارير</Btn></template>
        </PageHeader>
        <FinanceTabs />

        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Link href="/finance/accounts" class="card p-5 hover:ring-2 hover:ring-brand/20">
                <p class="flex items-center gap-2 text-xs text-slate-500"><Landmark class="size-4" /> النقد في الحسابات</p>
                <p class="num mt-1 text-2xl font-bold text-navy">{{ usd(d.cash.total) }}</p>
            </Link>
            <Link href="/finance/receivables" class="card p-5 hover:ring-2 hover:ring-brand/20">
                <p class="flex items-center gap-2 text-xs text-slate-500"><Clock4 class="size-4" /> مستحق على العملاء</p>
                <p class="num mt-1 text-2xl font-bold text-navy">{{ usd(d.receivables) }}</p>
                <p v-if="d.overdue" class="num text-xs font-semibold text-red-600">متأخر {{ usd(d.overdue) }}</p>
            </Link>
            <Link href="/finance/vendors" class="card p-5 hover:ring-2 hover:ring-brand/20">
                <p class="flex items-center gap-2 text-xs text-slate-500"><Truck class="size-4" /> مستحق للموردين</p>
                <p class="num mt-1 text-2xl font-bold text-red-600">{{ usd(d.payables) }}</p>
            </Link>
            <div class="card space-y-1.5 p-5 text-sm">
                <Link href="/finance/reimbursements" class="flex justify-between hover:underline"><span class="flex items-center gap-2 text-slate-500"><Undo2 class="size-4" /> للموظفين</span><span class="num font-semibold text-plum">{{ usd(d.to_reimburse) }}</span></Link>
                <Link href="/finance/advances" class="flex justify-between hover:underline"><span class="flex items-center gap-2 text-slate-500"><Wallet2 class="size-4" /> عهد مفتوحة</span><span class="num font-semibold">{{ usd(d.advances) }}</span></Link>
                <Link href="/finance/expenses?status=pending" class="flex justify-between hover:underline"><span class="flex items-center gap-2 text-slate-500"><ReceiptText class="size-4" /> مصروفات بانتظار الاعتماد</span><span class="num font-semibold text-amber-600">{{ d.pending_expenses }}</span></Link>
            </div>
        </div>

        <div class="mb-6 grid gap-6 xl:grid-cols-3">
            <div class="card p-5 xl:col-span-2">
                <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-navy">آخر 12 شهراً</h3>
                        <p class="text-xs text-slate-500">الإيراد (فواتير صادرة صافية من الضريبة) مقابل المصروفات المعتمدة</p>
                    </div>
                    <div class="flex gap-4 text-xs"><span class="flex items-center gap-1"><span class="size-2.5 rounded-sm bg-brand" /> الإيراد</span><span class="flex items-center gap-1"><span class="size-2.5 rounded-sm bg-orchid" /> المصروفات</span></div>
                </div>
                <div class="flex h-48 items-end gap-1.5" dir="ltr">
                    <div v-for="t in d.trend" :key="t.month" class="flex h-full flex-1 flex-col items-center justify-end gap-1" :title="`${t.month}: ${usd(t.revenue)} / ${usd(t.cost)} · صافٍ ${usd(t.net)}`">
                        <div class="flex h-full w-full items-end justify-center gap-0.5">
                            <div class="w-1/2 rounded-t bg-brand" :style="{ height: (t.revenue / max) * 100 + '%' }" />
                            <div class="w-1/2 rounded-t bg-orchid" :style="{ height: (t.cost / max) * 100 + '%' }" />
                        </div>
                        <span class="text-[10px] text-slate-500">{{ monthName(t.month) }}</span>
                    </div>
                </div>
            </div>
            <div class="card p-5">
                <h3 class="mb-3 font-semibold text-navy">هذا الشهر</h3>
                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between"><dt class="text-slate-500">الإيراد</dt><dd class="num text-lg font-bold text-navy">{{ usd(d.month.revenue) }}
                        <span v-if="revChange !== null" class="ms-1 inline-flex items-center text-xs" :class="revChange >= 0 ? 'text-emerald-600' : 'text-red-600'"><component :is="revChange >= 0 ? TrendingUp : TrendingDown" class="size-3.5" /> {{ Math.abs(revChange) }}%</span></dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">المصروفات</dt><dd class="num font-semibold">{{ usd(d.month.cost) }}</dd></div>
                    <div class="flex justify-between rounded-xl px-3 py-2 font-bold" :class="d.month.net >= 0 ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-700'"><dt>الصافي</dt><dd class="num">{{ usd(d.month.net) }}</dd></div>
                    <p class="text-xs text-slate-500">الشهر الماضي: صافٍ <span class="num">{{ usd(d.month.last_net) }}</span></p>
                </dl>
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="card p-5">
                <h3 class="mb-3 font-semibold text-navy">الحسابات</h3>
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="a in d.cash.accounts" :key="a.id" class="flex justify-between py-2"><Link :href="`/finance/accounts/${a.id}`" class="hover:underline">{{ a.name }}</Link><span class="num font-semibold" :class="a.balance < 0 ? 'text-red-600' : ''">{{ money(a.balance, a.currency) }}</span></li>
                    <li v-if="!d.cash.accounts.length" class="py-2 text-slate-500">أضف الصناديق والحسابات.</li>
                </ul>
            </div>
            <div class="card p-5">
                <h3 class="mb-3 font-semibold text-navy">أعلى المتأخرات</h3>
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="r in d.top_overdue" :key="r.client.id" class="flex justify-between py-2"><Link :href="`/finance/statements/${r.client.id}`" class="hover:underline">{{ r.client.name }}</Link><span class="num font-semibold text-red-600">{{ usd(r.overdue) }}</span></li>
                    <li v-if="!d.top_overdue.length" class="py-2 text-slate-500">لا متأخرات.</li>
                </ul>
            </div>
            <div class="card p-5">
                <h3 class="mb-3 font-semibold text-navy">ميزانيات تقترب أو تجاوزت</h3>
                <Link v-for="b in d.budgets" :key="b.id" :href="`/finance/budgets/${b.id}`" class="mb-3 block">
                    <p class="mb-1 flex justify-between text-xs"><span class="font-medium">{{ b.name }}</span><span class="num text-slate-500">{{ usd(b.actual) }} / {{ usd(b.planned) }}</span></p>
                    <BudgetBar :percent="b.percent" :alert="b.alert_percent" />
                </Link>
                <p v-if="!d.budgets.length" class="text-sm text-slate-500">كل الميزانيات الجارية ضمن الحد.</p>
            </div>
        </div>
    </AppLayout>
</template>

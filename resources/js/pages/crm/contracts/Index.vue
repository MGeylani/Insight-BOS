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
import CrmTabs from '@/components/crm/CrmTabs.vue';
import { useAuth } from '@/lib/auth';
import { formatDate } from '@/lib/format';
import { money, usd, contractStatusTone } from '@/lib/crm';

const props = defineProps({ contracts: Object, summary: Object, filters: Object, statuses: Object, types: Object, users: Array });
const { can } = useAuth();

const f = reactive({ search: props.filters.search ?? '', status: props.filters.status ?? '', type: props.filters.type ?? '', owner: props.filters.owner ?? '' });
let t;
watch(f, () => {
    clearTimeout(t);
    t = setTimeout(() => router.get('/crm/contracts', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300);
});
const stat = (status) => { f.status = f.status === status ? '' : status; };
</script>

<template>
    <AppLayout title="العقود">
        <PageHeader title="العملاء والمبيعات" subtitle="عقود المشاريع والعقود الشهرية، وجداول دفعاتها ومراحلها.">
            <template #actions>
                <Btn v-if="can('crm.create')" variant="secondary" href="/crm/contracts/create?type=retainer"><Plus class="size-4" /> عقد شهري</Btn>
                <Btn v-if="can('crm.create')" href="/crm/contracts/create"><Plus class="size-4" /> عقد مشروع</Btn>
            </template>
        </PageHeader>
        <CrmTabs />

        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <button class="card p-5 text-start transition hover:ring-2 hover:ring-brand/20" @click="stat('active')">
                <p class="text-xs text-slate-500">عقود سارية</p>
                <p class="num mt-1 text-2xl font-bold text-navy">{{ summary.active_count }}</p>
                <p class="mt-1 text-xs text-slate-500">دخل شهري متكرر <span class="num font-semibold text-emerald-700">{{ usd(summary.mrr_usd) }}</span></p>
            </button>
            <div class="card p-5">
                <p class="text-xs text-slate-500">دفعات مستحقة خلال 30 يوماً</p>
                <p class="num mt-1 text-2xl font-bold text-navy">{{ usd(summary.due_30_usd) }}</p>
                <p class="mt-1 text-xs text-slate-500"><span class="num">{{ summary.due_30_count }}</span> دفعة (مع المتأخرة)</p>
            </div>
            <button class="card p-5 text-start transition hover:ring-2 hover:ring-brand/20" @click="stat('sent')">
                <p class="text-xs text-slate-500">بانتظار توقيع العميل</p>
                <p class="num mt-1 text-2xl font-bold text-amber-700">{{ summary.awaiting_signature }}</p>
            </button>
            <button class="card p-5 text-start transition hover:ring-2 hover:ring-brand/20" @click="stat('expired')">
                <p class="text-xs text-slate-500">تنتهي خلال 30 يوماً أو انتهت</p>
                <p class="num mt-1 text-2xl font-bold" :class="summary.expiring_count ? 'text-red-600' : 'text-slate-700'">{{ summary.expiring_count }}</p>
            </button>
        </div>

        <div class="card overflow-hidden">
            <div class="flex flex-wrap gap-2 border-b border-slate-100 p-4">
                <div class="relative min-w-56 flex-1 sm:max-w-sm">
                    <Search class="absolute inset-y-0 start-3 my-auto size-4 text-slate-400" />
                    <input v-model="f.search" type="search" placeholder="رقم العقد، العنوان، العميل" class="field-input ps-9" />
                </div>
                <select v-model="f.type" class="field-input w-auto"><option value="">كل الأنواع</option><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select>
                <select v-model="f.status" class="field-input w-auto"><option value="">كل الحالات</option><option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option></select>
                <select v-model="f.owner" class="field-input w-auto"><option value="">كل المسؤولين</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select>
            </div>
            <div class="overflow-x-auto">
                <table v-if="contracts.data.length" class="tbl">
                    <thead><tr><th>الرقم</th><th>العقد</th><th>الحالة</th><th>القيمة</th><th>المدة</th><th>الدفعة القادمة</th><th>المسؤول</th></tr></thead>
                    <tbody>
                        <tr v-for="c in contracts.data" :key="c.id" class="cursor-pointer" @click="router.visit(`/crm/contracts/${c.id}`)">
                            <td class="num whitespace-nowrap font-semibold text-navy">{{ c.number ?? '—' }}</td>
                            <td><p class="font-medium text-slate-800">{{ c.title }}</p><p class="text-xs text-slate-500">{{ c.client?.name }} · {{ types[c.type] }}</p></td>
                            <td><Badge :tone="contractStatusTone[c.status]">{{ statuses[c.status] }}</Badge></td>
                            <td class="whitespace-nowrap">
                                <p v-if="c.type === 'retainer'" class="num font-semibold">{{ money(c.monthly_fee, c.currency) }} <span class="text-xs font-normal text-slate-500">/ شهر</span></p>
                                <p v-else class="num font-semibold">{{ money(c.total, c.currency) }}</p>
                                <p v-if="c.currency !== 'USD'" class="num text-xs text-slate-500">{{ usd(c.total_usd) }}</p>
                            </td>
                            <td class="whitespace-nowrap text-xs text-slate-600" :class="{ 'text-amber-700': c.status === 'expired' }">{{ formatDate(c.start_date) }}<template v-if="c.end_date"> ← {{ formatDate(c.end_date) }}</template></td>
                            <td class="whitespace-nowrap text-xs">
                                <template v-if="c.next_payment"><p class="num" :class="c.next_payment.overdue ? 'font-semibold text-amber-700' : 'text-slate-700'">{{ money(c.next_payment.amount, c.currency) }}</p><p class="text-slate-500">{{ formatDate(c.next_payment.due_date) }}</p></template>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                            <td class="text-slate-600">{{ c.owner?.name ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-else title="لا توجد عقود" text="أنشئ عقداً من هنا، أو من عرض سعر مقبول ليُنسخ منه العميل والقيمة ونطاق العمل.">
                    <Btn v-if="can('crm.create')" href="/crm/contracts/create"><Plus class="size-4" /> عقد مشروع</Btn>
                </EmptyState>
            </div>
            <Pagination :links="contracts.links" />
        </div>
    </AppLayout>
</template>

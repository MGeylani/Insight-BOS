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
import { money, usd, quoteStatusTone } from '@/lib/crm';

const props = defineProps({ quotes: Object, summary: Object, filters: Object, statuses: Object, users: Array });
const { can } = useAuth();

const f = reactive({ search: props.filters.search ?? '', status: props.filters.status ?? '', owner: props.filters.owner ?? '' });
let t;
watch(f, () => {
    clearTimeout(t);
    t = setTimeout(() => router.get('/crm/quotes', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300);
});
const stat = (status) => { f.status = f.status === status ? '' : status; };
</script>

<template>
    <AppLayout title="عروض الأسعار">
        <PageHeader title="العملاء والمبيعات" subtitle="عروض الأسعار: مسودات، عروض مُرسلة بانتظار الرد، وما قبله العملاء.">
            <template #actions><Btn v-if="can('crm.create')" href="/crm/quotes/create"><Plus class="size-4" /> عرض سعر</Btn></template>
        </PageHeader>
        <CrmTabs />

        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <button class="card p-5 text-start transition hover:ring-2 hover:ring-brand/20" @click="stat('sent')">
                <p class="text-xs text-slate-500">بانتظار رد العميل</p>
                <p class="num mt-1 text-2xl font-bold text-navy">{{ usd(summary.open_usd) }}</p>
                <p class="mt-1 text-xs text-slate-500"><span class="num">{{ summary.open_count }}</span> عرض مُرسَل</p>
            </button>
            <button class="card p-5 text-start transition hover:ring-2 hover:ring-brand/20" @click="stat('accepted')">
                <p class="text-xs text-slate-500">مقبول هذا الشهر</p>
                <p class="num mt-1 text-2xl font-bold text-emerald-700">{{ usd(summary.accepted_month_usd) }}</p>
            </button>
            <button class="card p-5 text-start transition hover:ring-2 hover:ring-brand/20" @click="stat('draft')">
                <p class="text-xs text-slate-500">مسودات</p>
                <p class="num mt-1 text-2xl font-bold text-slate-700">{{ summary.drafts }}</p>
            </button>
        </div>

        <div class="card overflow-hidden">
            <div class="flex flex-wrap gap-2 border-b border-slate-100 p-4">
                <div class="relative min-w-56 flex-1 sm:max-w-sm">
                    <Search class="absolute inset-y-0 start-3 my-auto size-4 text-slate-400" />
                    <input v-model="f.search" type="search" placeholder="رقم العرض، العنوان، العميل" class="field-input ps-9" />
                </div>
                <select v-model="f.status" class="field-input w-auto"><option value="">كل الحالات</option><option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option></select>
                <select v-model="f.owner" class="field-input w-auto"><option value="">كل المسؤولين</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select>
            </div>
            <div class="overflow-x-auto">
                <table v-if="quotes.data.length" class="tbl">
                    <thead><tr><th>الرقم</th><th>العرض</th><th>الحالة</th><th>الإجمالي</th><th>صالح حتى</th><th>المسؤول</th></tr></thead>
                    <tbody>
                        <tr v-for="q in quotes.data" :key="q.id" class="cursor-pointer" @click="router.visit(`/crm/quotes/${q.id}`)">
                            <td class="num whitespace-nowrap font-semibold text-navy">{{ q.number ?? '—' }}</td>
                            <td><p class="font-medium text-slate-800">{{ q.title }}</p><p class="text-xs text-slate-500">{{ q.client?.name }}</p></td>
                            <td><Badge :tone="quoteStatusTone[q.status]">{{ statuses[q.status] }}</Badge></td>
                            <td class="whitespace-nowrap"><p class="num font-semibold">{{ money(q.total, q.currency) }}</p><p v-if="q.currency !== 'USD'" class="num text-xs text-slate-500">{{ usd(q.total_usd) }}</p></td>
                            <td class="whitespace-nowrap text-slate-600" :class="{ 'text-amber-700': q.status === 'expired' }">{{ formatDate(q.valid_until) }}</td>
                            <td class="text-slate-600">{{ q.owner?.name ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-else title="لا توجد عروض أسعار" text="أنشئ عرض سعر من هنا أو من صفحة الفرصة ليرتبط بها تلقائياً.">
                    <Btn v-if="can('crm.create')" href="/crm/quotes/create"><Plus class="size-4" /> عرض سعر</Btn>
                </EmptyState>
            </div>
            <Pagination :links="quotes.links" />
        </div>
    </AppLayout>
</template>

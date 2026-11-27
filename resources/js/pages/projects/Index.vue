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
import { useAuth } from '@/lib/auth';
import { formatDate } from '@/lib/format';
import { money, usd } from '@/lib/crm';
import { projectStatusTone } from '@/lib/projects';

const props = defineProps({
    projects: Object, summary: Object, filters: Object, seeBudget: Boolean,
    types: Object, statuses: Object, users: Array,
});
const { can } = useAuth();

const f = reactive({ search: props.filters.search ?? '', status: props.filters.status ?? '', type: props.filters.type ?? '', manager: props.filters.manager ?? '' });
let t;
watch(f, () => {
    clearTimeout(t);
    t = setTimeout(() => router.get('/projects', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300);
});
const stat = (status) => { f.status = f.status === status ? '' : status; };
</script>

<template>
    <AppLayout title="المشاريع">
        <PageHeader title="المشاريع" subtitle="كل مشروع يتبع عميلاً، وله مدير وفريق ومراحل ومخاطر وطلبات تغيير.">
            <template #actions>
                <Btn v-if="can('projects.create')" href="/projects/create"><Plus class="size-4" /> مشروع جديد</Btn>
            </template>
        </PageHeader>

        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <button class="card p-5 text-start transition hover:ring-2 hover:ring-brand/20" @click="stat('active')">
                <p class="text-xs text-slate-500">قيد التنفيذ</p>
                <p class="num mt-1 text-2xl font-bold text-navy">{{ summary.active }}</p>
                <p class="mt-1 text-xs text-slate-500">و<span class="num">{{ summary.planning }}</span> في التخطيط</p>
            </button>
            <button class="card p-5 text-start transition hover:ring-2 hover:ring-brand/20" @click="stat('overdue')">
                <p class="text-xs text-slate-500">متأخرة عن موعد التسليم</p>
                <p class="num mt-1 text-2xl font-bold" :class="summary.overdue ? 'text-red-600' : 'text-slate-700'">{{ summary.overdue }}</p>
            </button>
            <button class="card p-5 text-start transition hover:ring-2 hover:ring-brand/20" @click="stat('on_hold')">
                <p class="text-xs text-slate-500">متوقفة مؤقتاً</p>
                <p class="num mt-1 text-2xl font-bold text-amber-700">{{ summary.on_hold }}</p>
            </button>
            <div class="card p-5">
                <p class="text-xs text-slate-500">مشكلات مفتوحة</p>
                <p class="num mt-1 text-2xl font-bold" :class="summary.open_issues ? 'text-amber-700' : 'text-slate-700'">{{ summary.open_issues }}</p>
                <p class="mt-1 text-xs text-slate-500"><span class="num">{{ summary.pending_changes }}</span> طلب تغيير بانتظار الاعتماد</p>
            </div>
        </div>

        <div class="card overflow-hidden">
            <div class="flex flex-wrap gap-2 border-b border-slate-100 p-4">
                <div class="relative min-w-56 flex-1 sm:max-w-sm">
                    <Search class="absolute inset-y-0 start-3 my-auto size-4 text-slate-400" />
                    <input v-model="f.search" type="search" placeholder="رقم المشروع، الاسم، العميل" class="field-input ps-9" />
                </div>
                <select v-model="f.type" class="field-input w-auto"><option value="">كل الأنواع</option><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select>
                <select v-model="f.status" class="field-input w-auto">
                    <option value="">كل الحالات</option>
                    <option value="open">المفتوحة</option>
                    <option value="overdue">المتأخرة</option>
                    <option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option>
                </select>
                <select v-model="f.manager" class="field-input w-auto"><option value="">كل المدراء</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select>
            </div>
            <div class="overflow-x-auto">
                <table v-if="projects.data.length" class="tbl">
                    <thead><tr><th>الرقم</th><th>المشروع</th><th>الحالة</th><th>التقدم</th><th>التسليم</th><th v-if="seeBudget">الميزانية</th><th>المدير</th></tr></thead>
                    <tbody>
                        <tr v-for="p in projects.data" :key="p.id" class="cursor-pointer" @click="router.visit(`/projects/${p.id}`)">
                            <td class="num whitespace-nowrap font-semibold text-navy">{{ p.number }}</td>
                            <td><p class="font-medium text-slate-800">{{ p.name }}</p><p class="text-xs text-slate-500">{{ p.client?.name }} · {{ types[p.type] }}</p></td>
                            <td>
                                <Badge :tone="projectStatusTone[p.status]">{{ statuses[p.status] }}</Badge>
                                <Badge v-if="p.overdue" tone="red" class="ms-1">متأخر</Badge>
                                <p v-if="p.open_issues" class="mt-1 text-xs text-amber-700"><span class="num">{{ p.open_issues }}</span> مشكلة مفتوحة</p>
                            </td>
                            <td class="w-32">
                                <template v-if="p.progress !== null">
                                    <div class="h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-brand" :style="{ width: p.progress + '%' }" /></div>
                                    <p class="num mt-1 text-xs text-slate-500">{{ p.progress }}%</p>
                                </template>
                                <span v-else class="text-xs text-slate-400">—</span>
                            </td>
                            <td class="whitespace-nowrap text-xs" :class="p.overdue ? 'font-semibold text-red-600' : 'text-slate-600'">{{ formatDate(p.due_date) }}</td>
                            <td v-if="seeBudget" class="whitespace-nowrap">
                                <p class="num font-semibold">{{ money(p.budget, p.currency) }}</p>
                                <p v-if="p.currency !== 'USD' && p.budget_usd !== null" class="num text-xs text-slate-500">{{ usd(p.budget_usd) }}</p>
                            </td>
                            <td class="text-slate-600">{{ p.manager?.name ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-else title="لا توجد مشاريع" text="أنشئ مشروعاً من هنا، أو من عرض سعر مقبول أو عقد ساري ليُنسخ منه العميل والقيمة.">
                    <Btn v-if="can('projects.create')" href="/projects/create"><Plus class="size-4" /> مشروع جديد</Btn>
                </EmptyState>
            </div>
            <Pagination :links="projects.links" />
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, ref, reactive, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Plus, Search, LayoutGrid, List, CalendarClock, UserRound } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Badge from '@/components/Badge.vue';
import EmptyState from '@/components/EmptyState.vue';
import CrmTabs from '@/components/crm/CrmTabs.vue';
import LeadFormModal from '@/components/crm/LeadFormModal.vue';
import { useAuth } from '@/lib/auth';
import { formatDate } from '@/lib/format';
import { ratingTone, leadStageAccent, money } from '@/lib/crm';

const props = defineProps({
    leads: Array, filters: Object, converted_count: Number,
    sources: Object, leadStages: Object, ratings: Object, oppStages: Array, lostReasons: Object,
    activityTypes: Object, clientTypes: Object, users: Array, currencies: Array,
});
const { can } = useAuth();
const options = computed(() => props);

const view = ref((() => { try { return localStorage.getItem('crm.leads.view'); } catch { return null; } })() || 'board');
watch(view, (v) => { try { localStorage.setItem('crm.leads.view', v); } catch {} });

const f = reactive({ search: props.filters.search ?? '', owner: props.filters.owner ?? '', source: props.filters.source ?? '', rating: props.filters.rating ?? '' });
let t;
watch(f, () => {
    clearTimeout(t);
    t = setTimeout(() => router.get('/crm/leads', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300);
});

// Local copy of the columns so drag & drop feels instant.
const columns = ref({});
watch(() => props.leads, (rows) => {
    const c = Object.fromEntries(Object.keys(props.leadStages).map((k) => [k, []]));
    rows.forEach((l) => c[l.stage]?.push(l));
    columns.value = c;
}, { immediate: true });

const total = (stage) => columns.value[stage]?.reduce((s, l) => s + Number(l.expected_value || 0), 0) ?? 0;

const dragging = ref(null);
const overStage = ref(null);
const overIndex = ref(null);
const canMove = computed(() => can('crm.edit'));

function onDragStart(lead) { dragging.value = lead; }
function onDragOver(stage, index) { overStage.value = stage; overIndex.value = index; }
function onDrop(stage) {
    const lead = dragging.value;
    if (!lead) return;
    const from = columns.value[lead.stage];
    from.splice(from.indexOf(lead), 1);
    const to = columns.value[stage];
    const idx = overIndex.value === null ? to.length : Math.min(overIndex.value, to.length);
    to.splice(idx, 0, lead);
    lead.stage = stage;
    dragging.value = overStage.value = overIndex.value = null;
    router.post(`/crm/leads/${lead.id}/move`, { stage, order: to.map((l) => l.id) }, { preserveScroll: true, preserveState: true });
}

const creating = ref(false);
const createStage = ref('new');
const openCreate = (stage = 'new') => { createStage.value = stage; creating.value = true; };
</script>

<template>
    <AppLayout title="العملاء المحتملون">
        <PageHeader title="العملاء والمبيعات" subtitle="من أول تواصل حتى توقيع العقد.">
            <template #actions>
                <Btn v-if="can('crm.create')" @click="openCreate()"><Plus class="size-4" /> عميل محتمل</Btn>
            </template>
        </PageHeader>
        <CrmTabs />

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <div class="relative min-w-56 flex-1 sm:max-w-xs">
                <Search class="absolute inset-y-0 start-3 my-auto size-4 text-slate-400" />
                <input v-model="f.search" type="search" placeholder="اسم، شركة، هاتف، بريد" class="field-input ps-9" />
            </div>
            <select v-model="f.owner" class="field-input w-auto"><option value="">كل المسؤولين</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select>
            <select v-model="f.source" class="field-input w-auto"><option value="">كل المصادر</option><option v-for="(l, k) in sources" :key="k" :value="k">{{ l }}</option></select>
            <select v-model="f.rating" class="field-input w-auto"><option value="">كل التقييمات</option><option v-for="(l, k) in ratings" :key="k" :value="k">{{ l }}</option></select>
            <div class="ms-auto flex rounded-xl border border-slate-200 bg-white p-0.5">
                <button class="rounded-lg p-2" :class="view === 'board' ? 'bg-navy text-white' : 'text-slate-500'" title="لوحة" @click="view = 'board'"><LayoutGrid class="size-4" /></button>
                <button class="rounded-lg p-2" :class="view === 'list' ? 'bg-navy text-white' : 'text-slate-500'" title="قائمة" @click="view = 'list'"><List class="size-4" /></button>
            </div>
        </div>

        <!-- Kanban -->
        <div v-if="view === 'board'" class="-mx-4 overflow-x-auto px-4 pb-4 sm:mx-0 sm:px-0">
            <div class="grid min-w-[960px] grid-cols-4 gap-4">
                <section v-for="(label, stage) in leadStages" :key="stage"
                    class="flex flex-col rounded-2xl border-t-4 bg-slate-100/70 transition"
                    :class="[leadStageAccent[stage], overStage === stage && dragging ? 'ring-2 ring-brand/40' : '']"
                    @dragover.prevent="onDragOver(stage, columns[stage].length)" @drop.prevent="onDrop(stage)">
                    <header class="flex items-center justify-between px-3 pt-3 pb-2">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-800">{{ label }} <span class="num ms-1 text-slate-400">{{ columns[stage].length }}</span></h3>
                            <p class="num text-xs text-slate-500">{{ money(total(stage), 'USD') }}</p>
                        </div>
                        <button v-if="can('crm.create')" class="rounded-lg p-1.5 text-slate-400 hover:bg-white hover:text-navy" title="إضافة هنا" @click="openCreate(stage)"><Plus class="size-4" /></button>
                    </header>
                    <div class="flex min-h-32 flex-1 flex-col gap-2 p-2">
                        <article v-for="(l, i) in columns[stage]" :key="l.id"
                            :draggable="canMove" class="group rounded-xl border border-slate-200 bg-white p-3 shadow-sm transition hover:border-brand/40 hover:shadow"
                            :class="{ 'cursor-grab active:cursor-grabbing': canMove, 'opacity-40': dragging?.id === l.id }"
                            @dragstart="onDragStart(l)" @dragend="dragging = null" @dragover.prevent.stop="onDragOver(stage, i)">
                            <Link :href="`/crm/leads/${l.id}`" class="block">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="font-medium leading-snug text-slate-800 group-hover:text-navy">{{ l.company_name || l.name }}</p>
                                    <Badge v-if="l.rating" :tone="ratingTone[l.rating]">{{ ratings[l.rating] }}</Badge>
                                </div>
                                <p v-if="l.company_name" class="mt-0.5 text-xs text-slate-500">{{ l.name }}</p>
                                <p v-if="l.interest" class="mt-1.5 line-clamp-2 text-xs text-slate-600">{{ l.interest }}</p>
                                <div class="mt-2.5 flex items-center justify-between text-xs text-slate-500">
                                    <span class="num font-semibold text-navy">{{ l.expected_value ? money(l.expected_value, l.currency) : '' }}</span>
                                    <span class="flex items-center gap-2">
                                        <span v-if="l.pending_count" class="flex items-center gap-0.5 text-brand" title="متابعات مفتوحة"><CalendarClock class="size-3.5" /> {{ l.pending_count }}</span>
                                        <span v-if="l.owner" class="flex items-center gap-0.5"><UserRound class="size-3.5" /> {{ l.owner.name.split(' ')[0] }}</span>
                                    </span>
                                </div>
                            </Link>
                        </article>
                        <p v-if="!columns[stage].length" class="py-6 text-center text-xs text-slate-400">اسحب بطاقة إلى هنا</p>
                    </div>
                </section>
            </div>
        </div>

        <!-- List -->
        <div v-else class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table v-if="leads.length" class="tbl">
                    <thead><tr><th>العميل المحتمل</th><th>المرحلة</th><th>التقييم</th><th>المصدر</th><th>القيمة المتوقعة</th><th>المسؤول</th><th>أُضيف</th></tr></thead>
                    <tbody>
                        <tr v-for="l in leads" :key="l.id" class="cursor-pointer" @click="router.visit(`/crm/leads/${l.id}`)">
                            <td><p class="font-medium text-slate-800">{{ l.company_name || l.name }}</p><p v-if="l.company_name" class="text-xs text-slate-500">{{ l.name }}</p></td>
                            <td>{{ leadStages[l.stage] }}</td>
                            <td><Badge v-if="l.rating" :tone="ratingTone[l.rating]">{{ ratings[l.rating] }}</Badge></td>
                            <td class="text-slate-600">{{ sources[l.source] ?? '—' }}</td>
                            <td class="num">{{ money(l.expected_value, l.currency) }}</td>
                            <td class="text-slate-600">{{ l.owner?.name ?? '—' }}</td>
                            <td class="text-slate-500">{{ formatDate(l.created_at) }}</td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-else title="لا يوجد عملاء محتملون" text="أضف أول عميل محتمل لتبدأ المتابعة." />
            </div>
        </div>
        <p v-if="converted_count" class="mt-3 text-xs text-slate-500"><span class="num">{{ converted_count }}</span> سجل تم تحويله إلى عملاء ويظهر في صفحة العملاء.</p>

        <LeadFormModal v-model="creating" :options="options" :default-stage="createStage" />
    </AppLayout>
</template>

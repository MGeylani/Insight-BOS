<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Plus, Search, TriangleAlert, CalendarDays } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Badge from '@/components/Badge.vue';
import Toggle from '@/components/Toggle.vue';
import CrmTabs from '@/components/crm/CrmTabs.vue';
import OpportunityFormModal from '@/components/crm/OpportunityFormModal.vue';
import StageModal from '@/components/crm/StageModal.vue';
import { useAuth } from '@/lib/auth';
import { formatDate } from '@/lib/format';
import { oppStageTone, money, usd, monthLabel } from '@/lib/crm';

const props = defineProps({
    opportunities: Array, forecast: Object, filters: Object, clients: Array,
    sources: Object, leadStages: Object, ratings: Object, oppStages: Array, lostReasons: Object,
    activityTypes: Object, clientTypes: Object, users: Array, currencies: Array,
});
const { can } = useAuth();
const options = computed(() => props);
const creating = ref(false);

const f = reactive({ search: props.filters.search ?? '', owner: props.filters.owner ?? '', closed: Boolean(props.filters.closed) });
let t;
watch(f, () => {
    clearTimeout(t);
    t = setTimeout(() => router.get('/crm/opportunities', { search: f.search || undefined, owner: f.owner || undefined, closed: f.closed ? 1 : undefined }, { preserveState: true, replace: true }), 300);
});

const byStage = computed(() => Object.fromEntries(props.oppStages.map((s) => [s.key, props.opportunities.filter((o) => o.stage === s.key)])));
const stageSum = (k) => byStage.value[k].reduce((s, o) => s + (o.weighted_usd ?? 0), 0);
const maxMonth = computed(() => Math.max(1, ...props.forecast.months.map((m) => m.total)));

// Drag a card to a column; closing stages open the confirmation dialog.
const dragging = ref(null);
const over = ref(null);
const stageModal = ref(false);
const target = ref({ opp: null, stage: null });
function drop(stage) {
    const o = dragging.value;
    dragging.value = over.value = null;
    if (!o || o.stage === stage) return;
    target.value = { opp: o, stage };
    if (['won', 'lost'].includes(stage)) {
        stageModal.value = true;
    } else {
        router.post(`/crm/opportunities/${o.id}/stage`, { stage }, { preserveScroll: true, preserveState: true });
    }
}
</script>

<template>
    <AppLayout title="الفرص">
        <PageHeader title="العملاء والمبيعات" subtitle="مسار الفرص والتوقعات الموزونة بالدولار.">
            <template #actions><Btn v-if="can('crm.create')" @click="creating = true"><Plus class="size-4" /> فرصة</Btn></template>
        </PageHeader>
        <CrmTabs />

        <!-- Forecast -->
        <div class="mb-6 grid gap-4 lg:grid-cols-3">
            <div class="card brand-gradient p-5 text-white">
                <p class="text-sm text-white/70">التوقع الموزون (الفرص المفتوحة)</p>
                <p class="num mt-1 text-3xl font-bold">{{ usd(forecast.weighted) }}</p>
                <p class="mt-1 text-sm text-white/70">من إجمالي <span class="num">{{ usd(forecast.total) }}</span> في <span class="num">{{ forecast.count }}</span> فرصة</p>
                <p v-if="forecast.missing_rates.length" class="mt-3 flex items-center gap-1 text-xs text-sand"><TriangleAlert class="size-3.5" /> لا يوجد سعر صرف لـ {{ forecast.missing_rates.join('، ') }}؛ استُبعدت من التوقع.</p>
            </div>
            <div class="card p-5 lg:col-span-2">
                <p class="mb-3 text-sm font-semibold text-navy">حسب شهر الإغلاق المتوقع</p>
                <div v-if="forecast.months.length" class="space-y-2.5">
                    <div v-for="m in forecast.months" :key="m.month" class="grid grid-cols-[7rem_1fr_6rem] items-center gap-3 text-sm">
                        <span class="text-slate-600">{{ monthLabel(m.month) }}</span>
                        <div class="relative h-5 overflow-hidden rounded-full bg-slate-100">
                            <div class="absolute inset-y-0 start-0 rounded-full bg-sky/30" :style="{ width: (m.total / maxMonth) * 100 + '%' }" />
                            <div class="absolute inset-y-0 start-0 rounded-full bg-brand" :style="{ width: (m.weighted / maxMonth) * 100 + '%' }" />
                        </div>
                        <span class="num text-end font-semibold text-navy">{{ usd(m.weighted) }}</span>
                    </div>
                    <p class="pt-1 text-xs text-slate-400"><span class="me-1 inline-block size-2 rounded-full bg-brand" />موزون <span class="ms-3 me-1 inline-block size-2 rounded-full bg-sky/40" />إجمالي</p>
                </div>
                <p v-else class="py-6 text-center text-sm text-slate-400">لا توجد فرص مفتوحة.</p>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <div class="relative min-w-56 flex-1 sm:max-w-xs">
                <Search class="absolute inset-y-0 start-3 my-auto size-4 text-slate-400" />
                <input v-model="f.search" type="search" placeholder="الفرصة أو العميل" class="field-input ps-9" />
            </div>
            <select v-model="f.owner" class="field-input w-auto"><option value="">كل المسؤولين</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select>
            <Toggle v-model="f.closed" label="إظهار كل المغلقة (وليس آخر 30 يوماً فقط)" />
        </div>

        <div class="-mx-4 overflow-x-auto px-4 pb-4 sm:mx-0 sm:px-0">
            <div class="grid min-w-[1200px] grid-cols-6 gap-3">
                <section v-for="s in oppStages" :key="s.key" class="flex flex-col rounded-2xl bg-slate-100/70 transition"
                    :class="{ 'ring-2 ring-brand/40': over === s.key && dragging }"
                    @dragover.prevent="over = s.key" @drop.prevent="drop(s.key)">
                    <header class="px-3 pt-3 pb-2">
                        <div class="flex items-center justify-between"><Badge :tone="oppStageTone[s.key]">{{ s.label }}</Badge><span class="num text-xs text-slate-400">{{ s.probability }}%</span></div>
                        <p class="num mt-1.5 text-xs text-slate-500"><span class="num">{{ byStage[s.key].length }}</span> · {{ usd(stageSum(s.key)) }}</p>
                    </header>
                    <div class="flex min-h-32 flex-1 flex-col gap-2 p-2">
                        <article v-for="o in byStage[s.key]" :key="o.id" :draggable="can('crm.edit')"
                            class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm transition hover:border-brand/40"
                            :class="{ 'cursor-grab': can('crm.edit'), 'opacity-40': dragging?.id === o.id }"
                            @dragstart="dragging = o" @dragend="dragging = null">
                            <Link :href="`/crm/opportunities/${o.id}`" class="block">
                                <p class="font-medium leading-snug text-slate-800">{{ o.title }}</p>
                                <p class="mt-0.5 text-xs text-slate-500">{{ o.client?.name }}</p>
                                <p class="num mt-2 text-sm font-semibold text-navy">{{ money(o.amount, o.currency) }}</p>
                                <p v-if="o.expected_close_date" class="mt-1 flex items-center gap-1 text-xs" :class="o.overdue ? 'text-red-600' : 'text-slate-500'">
                                    <CalendarDays class="size-3.5" /> {{ formatDate(o.expected_close_date) }}
                                </p>
                                <p v-if="o.stage === 'lost' && o.lost_reason" class="mt-1 text-xs text-red-600">{{ lostReasons[o.lost_reason] }}</p>
                            </Link>
                        </article>
                    </div>
                </section>
            </div>
        </div>

        <OpportunityFormModal v-model="creating" :options="options" :clients="clients" />
        <StageModal v-model="stageModal" :opportunity="target.opp" :stage="target.stage" :options="options" />
    </AppLayout>
</template>

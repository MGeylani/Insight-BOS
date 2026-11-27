<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Pencil, Archive, Trophy, XCircle, Building2, UserRound, CalendarDays, Funnel } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Badge from '@/components/Badge.vue';
import CrmTabs from '@/components/crm/CrmTabs.vue';
import OpportunityFormModal from '@/components/crm/OpportunityFormModal.vue';
import StageModal from '@/components/crm/StageModal.vue';
import ActivityFeed from '@/components/crm/ActivityFeed.vue';
import QuoteList from '@/components/crm/QuoteList.vue';
import ContractList from '@/components/crm/ContractList.vue';
import { useAuth } from '@/lib/auth';
import { formatDate, formatDateTime } from '@/lib/format';
import { oppStageTone, money, usd } from '@/lib/crm';

const props = defineProps({
    opportunity: Object, history: Array, activities: Array, contacts: Array, quotes: Array, quoteStatuses: Object, contracts: Array, contractStatuses: Object,
    sources: Object, leadStages: Object, ratings: Object, oppStages: Array, lostReasons: Object,
    activityTypes: Object, clientTypes: Object, users: Array, currencies: Array,
});
const { can } = useAuth();
const options = computed(() => props);
const o = computed(() => props.opportunity);
const stageLabel = (k) => props.oppStages.find((s) => s.key === k)?.label ?? '—';
const openStages = computed(() => props.oppStages.filter((s) => !['won', 'lost'].includes(s.key)));
const closed = computed(() => ['won', 'lost'].includes(o.value.stage));

const editing = ref(false);
const stageOpen = ref(false);
const targetStage = ref(null);
const moveTo = (stage) => { targetStage.value = stage; stageOpen.value = true; };
const archive = () => confirm('أرشفة هذه الفرصة؟') && router.delete(`/crm/opportunities/${o.value.id}`);
</script>

<template>
    <AppLayout :title="o.title">
        <PageHeader :title="o.title" :subtitle="o.client?.name">
            <template #actions>
                <template v-if="o.can_edit && !closed">
                    <Btn variant="secondary" class="!text-emerald-700" @click="moveTo('won')"><Trophy class="size-4" /> مكسوبة</Btn>
                    <Btn variant="secondary" class="!text-red-600" @click="moveTo('lost')"><XCircle class="size-4" /> خاسرة</Btn>
                </template>
                <Btn v-if="o.can_edit && closed" variant="secondary" @click="moveTo('negotiation')">إعادة فتح</Btn>
                <Btn v-if="o.can_edit" variant="secondary" @click="editing = true"><Pencil class="size-4" /> تعديل</Btn>
                <Btn v-if="can('crm.delete')" variant="ghost" @click="archive"><Archive class="size-4" /></Btn>
            </template>
        </PageHeader>
        <CrmTabs />

        <!-- Stage stepper -->
        <div v-if="!closed" class="card mb-6 flex overflow-x-auto p-1.5">
            <button v-for="(s, i) in openStages" :key="s.key" :disabled="!o.can_edit"
                class="flex min-w-36 flex-1 items-center justify-center gap-2 rounded-xl px-3 py-2.5 text-sm transition disabled:cursor-default"
                :class="s.key === o.stage ? 'bg-navy text-white font-semibold' : openStages.findIndex((x) => x.key === o.stage) > i ? 'bg-navy-50 text-navy' : 'text-slate-500 hover:bg-slate-100'"
                @click="s.key !== o.stage && moveTo(s.key)">
                {{ s.label }} <span class="num text-xs opacity-70">{{ s.key === o.stage ? o.probability : s.probability }}%</span>
            </button>
        </div>
        <div v-else class="mb-6 rounded-2xl p-4 text-sm" :class="o.stage === 'won' ? 'bg-emerald-50 text-emerald-900' : 'bg-red-50 text-red-900'">
            <strong>{{ stageLabel(o.stage) }}</strong> في {{ formatDate(o.closed_at) }}
            <template v-if="o.stage === 'lost'"> · السبب: <strong>{{ lostReasons[o.lost_reason] }}</strong><template v-if="o.lost_note"> — {{ o.lost_note }}</template></template>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6">
                <div class="card p-5">
                    <div class="grid grid-cols-2 gap-4">
                        <div><p class="text-xs text-slate-500">القيمة</p><p class="num text-xl font-bold text-navy">{{ money(o.amount, o.currency) }}</p></div>
                        <div><p class="text-xs text-slate-500">الموزونة (USD)</p><p class="num text-xl font-bold text-brand">{{ usd(o.weighted_usd) }}</p></div>
                    </div>
                    <dl class="mt-5 space-y-3 border-t border-slate-100 pt-4 text-sm">
                        <div class="flex items-center gap-2"><Building2 class="size-4 text-slate-400" /><Link :href="`/crm/clients/${o.client?.id}`" class="text-brand hover:underline">{{ o.client?.name }}</Link></div>
                        <div v-if="o.contact" class="flex items-center gap-2"><UserRound class="size-4 text-slate-400" />{{ o.contact.name }} <span v-if="o.contact.phone" dir="ltr" class="text-xs text-slate-500">{{ o.contact.phone }}</span></div>
                        <div class="flex items-center gap-2" :class="{ 'text-red-600': o.overdue }"><CalendarDays class="size-4 text-slate-400" />الإغلاق المتوقع: {{ formatDate(o.expected_close_date) }}</div>
                        <div class="flex items-center gap-2"><UserRound class="size-4 text-slate-400" />المسؤول: {{ o.owner?.name ?? '—' }}</div>
                        <div v-if="o.lead" class="flex items-center gap-2"><Funnel class="size-4 text-slate-400" />من المحتمل <Link :href="`/crm/leads/${o.lead.id}`" class="text-brand hover:underline">{{ o.lead.name }}</Link></div>
                    </dl>
                    <div v-if="o.notes" class="mt-4 border-t border-slate-100 pt-4 text-sm"><p class="text-xs text-slate-500">ملاحظات</p><p class="whitespace-pre-line">{{ o.notes }}</p></div>
                </div>
                <div class="card p-5">
                    <h3 class="mb-3 font-semibold text-navy">سجل المراحل</h3>
                    <ol class="relative space-y-4 border-s border-slate-200 ps-4">
                        <li v-for="h in history" :key="h.id" class="text-sm">
                            <span class="absolute -start-1.5 mt-1.5 size-3 rounded-full border-2 border-white bg-brand" />
                            <Badge :tone="oppStageTone[h.to_stage]">{{ stageLabel(h.to_stage) }} · <span class="num">{{ h.probability }}%</span></Badge>
                            <p class="mt-1 text-xs text-slate-500">{{ h.from_stage ? 'من ' + stageLabel(h.from_stage) + ' · ' : 'البداية · ' }}{{ h.user?.name ?? '—' }} · {{ formatDateTime(h.created_at) }}</p>
                        </li>
                    </ol>
                </div>
            </div>
            <div class="space-y-6 lg:col-span-2">
                <QuoteList :quotes="quotes" :statuses="quoteStatuses" :create-href="closed ? null : `/crm/quotes/create?opportunity_id=${o.id}`" />
                <ContractList :contracts="contracts" :statuses="contractStatuses" :create-href="o.stage === 'lost' ? null : `/crm/contracts/create?opportunity_id=${o.id}`" />
                <ActivityFeed :activities="activities" subject-type="opportunity" :subject-id="o.id" :options="options" />
            </div>
        </div>

        <OpportunityFormModal v-model="editing" :opportunity="o" :options="options" :contacts="contacts" :client-id="o.client_id" />
        <StageModal v-model="stageOpen" :opportunity="o" :stage="targetStage" :options="options" />
    </AppLayout>
</template>

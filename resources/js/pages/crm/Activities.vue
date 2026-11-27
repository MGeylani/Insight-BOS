<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Check, Funnel, Building2, Handshake, Bell } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Badge from '@/components/Badge.vue';
import EmptyState from '@/components/EmptyState.vue';
import CrmTabs from '@/components/crm/CrmTabs.vue';
import { formatDateTime } from '@/lib/format';

const props = defineProps({
    overdue: Array, today: Array, upcoming: Array, done: Array, who: String,
    sources: Object, leadStages: Object, ratings: Object, oppStages: Array, lostReasons: Object,
    activityTypes: Object, clientTypes: Object, users: Array, currencies: Array,
});
const subjectIcon = { lead: Funnel, client: Building2, opportunity: Handshake };
const groups = computed(() => [
    { key: 'overdue', title: 'متأخرة', rows: props.overdue, tone: 'text-red-600' },
    { key: 'today', title: 'اليوم', rows: props.today, tone: 'text-brand' },
    { key: 'upcoming', title: 'قادمة', rows: props.upcoming, tone: 'text-slate-700' },
    { key: 'done', title: 'أُنجزت مؤخراً', rows: props.done, tone: 'text-emerald-700' },
]);
const empty = computed(() => groups.value.every((g) => !g.rows.length));
const toggle = (a) => router.post(`/crm/activities/${a.id}/complete`, {}, { preserveScroll: true });
const setWho = (w) => router.get('/crm/activities', { who: w }, { preserveState: true, replace: true });
</script>

<template>
    <AppLayout title="المتابعات">
        <PageHeader title="العملاء والمبيعات" subtitle="كل المكالمات والاجتماعات والمتابعات المجدولة.">
            <template #actions>
                <div class="flex rounded-xl border border-slate-200 bg-white p-0.5 text-sm">
                    <button class="rounded-lg px-3 py-1.5" :class="who === 'mine' ? 'bg-navy text-white' : 'text-slate-600'" @click="setWho('mine')">متابعاتي</button>
                    <button class="rounded-lg px-3 py-1.5" :class="who === 'all' ? 'bg-navy text-white' : 'text-slate-600'" @click="setWho('all')">الكل</button>
                </div>
            </template>
        </PageHeader>
        <CrmTabs />

        <div v-if="!empty" class="space-y-6">
            <template v-for="g in groups" :key="g.key">
                <section v-if="g.rows.length" class="card overflow-hidden">
                    <h3 class="border-b border-slate-100 px-5 py-3 font-semibold" :class="g.tone">{{ g.title }} <span class="num text-slate-400">{{ g.rows.length }}</span></h3>
                    <ul class="divide-y divide-slate-100">
                        <li v-for="a in g.rows" :key="a.id" class="flex items-center gap-3 px-5 py-3" :class="{ 'opacity-60': a.completed_at }">
                            <button class="flex size-7 shrink-0 items-center justify-center rounded-full border transition"
                                :class="a.completed_at ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-slate-300 text-transparent hover:border-navy hover:text-navy'" @click="toggle(a)">
                                <Check class="size-4" />
                            </button>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-slate-800" :class="{ 'line-through': a.completed_at }">{{ a.title }}</p>
                                <p class="flex flex-wrap items-center gap-x-2 text-xs text-slate-500">
                                    <span>{{ activityTypes[a.type] }}</span>
                                    <Link v-if="a.subject" :href="a.subject.url" class="flex items-center gap-1 text-brand hover:underline"><component :is="subjectIcon[a.subject.type]" class="size-3.5" />{{ a.subject.name }}</Link>
                                    <span v-if="who === 'all'">· {{ a.user?.name }}</span>
                                </p>
                            </div>
                            <Badge v-if="a.remind_at && !a.completed_at" tone="sky"><Bell class="size-3" /></Badge>
                            <span class="whitespace-nowrap text-xs" :class="g.key === 'overdue' ? 'text-red-600' : 'text-slate-500'">{{ a.due_at ? formatDateTime(a.due_at) : 'بلا موعد' }}</span>
                        </li>
                    </ul>
                </section>
            </template>
        </div>
        <div v-else class="card"><EmptyState title="لا توجد متابعات" text="سجّل الأنشطة من صفحة العميل المحتمل أو العميل أو الفرصة." /></div>
    </AppLayout>
</template>

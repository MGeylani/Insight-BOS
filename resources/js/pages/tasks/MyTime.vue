<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ChevronRight, ChevronLeft, Plus, Trash2, DollarSign, Play } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import MyWorkTabs from '@/components/tasks/MyWorkTabs.vue';
import TimeEntryForm from '@/components/tasks/TimeEntryForm.vue';
import { minutesLabel, parseLocal, keep } from '@/lib/tasks';
import { usd } from '@/lib/crm';

const props = defineProps({ who: Object, week: Object, entries: Array, totals: Object, seeCost: Boolean, people: Array, myTasks: Array });
const adding = ref(false);
const dayFmt = new Intl.DateTimeFormat('ar', { weekday: 'long', day: 'numeric', month: 'short' });
const days = computed(() => Array.from({ length: 7 }, (_, i) => {
    const d = parseLocal(props.week.start);
    d.setDate(d.getDate() + i);
    const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const rows = props.entries.filter((e) => e.date === key);
    return { key, label: dayFmt.format(d), rows, minutes: rows.reduce((a, e) => a + e.minutes, 0) };
}));
const shift = (n) => { const d = parseLocal(props.week.start); d.setDate(d.getDate() + n * 7); go({ week: d.toISOString().slice(0, 10) }); };
const go = (p) => router.get('/my-work/time', { week: props.week.start, user: props.who.id, ...p }, { preserveState: true, preserveScroll: true });
const toggleBillable = (e) => router.patch(`/time-entries/${e.id}`, { billable: !e.billable }, keep);
const remove = (e) => confirm('حذف قيد الوقت؟') && router.delete(`/time-entries/${e.id}`, keep);
const restart = (e) => router.post('/timer/start', { task_id: e.task?.id ?? null }, keep);
</script>

<template>
    <AppLayout title="وقتي">
        <PageHeader title="وقتي" :subtitle="`أسبوع ${week.start} ← ${week.end}`">
            <template #actions>
                <select v-if="people.length" :value="who.id" class="field-input w-auto" @change="go({ user: $event.target.value })">
                    <option v-for="p in people" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
                <Btn variant="secondary" title="الأسبوع السابق" @click="shift(-1)"><ChevronRight class="size-4" /></Btn>
                <Btn variant="secondary" @click="go({ week: undefined })">هذا الأسبوع</Btn>
                <Btn variant="secondary" title="الأسبوع التالي" @click="shift(1)"><ChevronLeft class="size-4" /></Btn>
                <Btn @click="adding = !adding"><Plus class="size-4" /> تسجيل وقت</Btn>
            </template>
        </PageHeader>
        <MyWorkTabs />

        <div class="mb-6 grid gap-4 sm:grid-cols-4">
            <div class="card p-4"><p class="text-xs text-slate-500">المجموع</p><p class="num text-2xl font-bold text-navy">{{ minutesLabel(totals.minutes) }}</p></div>
            <div class="card p-4"><p class="text-xs text-slate-500">قابلة للفوترة</p><p class="num text-2xl font-bold text-emerald-700">{{ minutesLabel(totals.billable_minutes) }}</p></div>
            <div class="card p-4"><p class="text-xs text-slate-500">غير قابلة للفوترة</p><p class="num text-2xl font-bold text-slate-600">{{ minutesLabel(totals.minutes - totals.billable_minutes) }}</p></div>
            <div v-if="seeCost" class="card p-4"><p class="text-xs text-slate-500">التكلفة</p><p class="num text-2xl font-bold text-plum">{{ usd(totals.cost) }}</p>
                <p v-if="totals.uncosted_minutes" class="text-[11px] text-amber-700">{{ minutesLabel(totals.uncosted_minutes) }} بلا تكلفة ساعة</p></div>
        </div>

        <TimeEntryForm v-if="adding" :tasks="myTasks" :user-id="who.id" class="mb-6" @done="adding = false" />

        <div class="space-y-4">
            <div v-for="d in days" :key="d.key" class="card overflow-hidden">
                <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50/60 px-4 py-2 text-sm">
                    <span class="font-semibold text-slate-700">{{ d.label }}</span><span class="num text-slate-500">{{ minutesLabel(d.minutes) || '—' }}</span>
                </div>
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="e in d.rows" :key="e.id" class="group flex items-center gap-3 px-4 py-2">
                        <span class="num w-24 text-xs text-slate-500" dir="ltr">{{ e.from }}{{ e.to ? ' – ' + e.to : '' }}</span>
                        <span class="num w-16 font-semibold" :class="e.running ? 'text-emerald-600' : 'text-slate-800'">{{ e.running ? 'يعمل…' : minutesLabel(e.minutes) }}</span>
                        <span class="min-w-0 flex-1 truncate">
                            <Link v-if="e.task" :href="`/tasks/${e.task.id}`" class="hover:text-brand"><span class="num text-xs text-slate-400" dir="ltr">{{ e.task.code }}</span> {{ e.task.title }}</Link>
                            <span v-else class="text-slate-500">بلا مهمة</span>
                            <span v-if="e.note" class="text-xs text-slate-500"> · {{ e.note }}</span>
                        </span>
                        <span v-if="seeCost && e.cost !== null" class="num text-xs text-slate-500">{{ usd(e.cost) }}</span>
                        <button type="button" class="rounded p-1" :class="e.billable ? 'text-emerald-600' : 'text-slate-300'" :title="e.billable ? 'قابلة للفوترة' : 'غير قابلة للفوترة'" @click="toggleBillable(e)"><DollarSign class="size-4" /></button>
                        <button v-if="!e.running" type="button" class="rounded p-1 text-slate-300 opacity-0 hover:text-emerald-600 group-hover:opacity-100" title="مؤقت جديد على نفس المهمة" @click="restart(e)"><Play class="size-4" /></button>
                        <button type="button" class="rounded p-1 text-slate-300 opacity-0 hover:text-red-600 group-hover:opacity-100" @click="remove(e)"><Trash2 class="size-4" /></button>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>

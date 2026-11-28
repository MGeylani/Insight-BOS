<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Timer, Play, Square, Plus, Trash2, DollarSign } from '@lucide/vue';
import Btn from '@/components/Btn.vue';
import TimeEntryForm from './TimeEntryForm.vue';
import { minutesLabel, keep } from '@/lib/tasks';
import { formatDate } from '@/lib/format';

const props = defineProps({ task: Object, time: Object, canTrack: Boolean });
const page = usePage();
const running = computed(() => page.props.timer?.task?.id === props.task.id);
const adding = ref(false);
const start = () => router.post('/timer/start', { task_id: props.task.id }, keep);
const stop = () => router.post('/timer/stop', {}, keep);
const toggleBillable = (e) => router.patch(`/time-entries/${e.id}`, { billable: !e.billable }, keep);
const remove = (e) => confirm('حذف قيد الوقت؟') && router.delete(`/time-entries/${e.id}`, keep);
const pct = computed(() => (props.task.estimate ? Math.min(100, Math.round((props.time.total / props.task.estimate) * 100)) : null));
</script>

<template>
    <div class="card p-5 text-sm">
        <div class="mb-3 flex items-center gap-2">
            <Timer class="size-4 text-emerald-600" /><h3 class="me-auto font-semibold text-navy">الوقت</h3>
            <template v-if="canTrack">
                <Btn v-if="running" size="sm" variant="danger" @click="stop"><Square class="size-3.5" fill="currentColor" /> إيقاف</Btn>
                <Btn v-else size="sm" variant="sky" @click="start"><Play class="size-3.5" fill="currentColor" /> بدء المؤقت</Btn>
                <Btn size="sm" variant="secondary" title="تسجيل يدوي" @click="adding = !adding"><Plus class="size-4" /></Btn>
            </template>
        </div>
        <div class="mb-3 flex items-baseline gap-2">
            <span class="num text-xl font-bold text-navy">{{ minutesLabel(time.total) }}</span>
            <span v-if="task.estimate" class="text-xs text-slate-500">من تقدير {{ minutesLabel(task.estimate) }}</span>
            <span v-if="time.mine && time.mine !== time.total" class="ms-auto text-xs text-slate-500">منها لي {{ minutesLabel(time.mine) }}</span>
        </div>
        <div v-if="pct !== null" class="mb-3 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full" :class="time.total > task.estimate ? 'bg-red-500' : 'bg-emerald-500'" :style="{ width: pct + '%' }" /></div>
        <TimeEntryForm v-if="adding" :task-id="task.id" class="mb-3" @done="adding = false" />
        <ul class="space-y-1.5">
            <li v-for="e in time.entries" :key="e.id" class="group flex items-center gap-2 text-xs">
                <span class="w-20 text-slate-500">{{ formatDate(e.date) }}</span>
                <span class="num w-14 font-semibold" :class="e.running ? 'text-emerald-600' : 'text-slate-700'">{{ e.running ? 'يعمل…' : minutesLabel(e.minutes) }}</span>
                <span class="min-w-0 flex-1 truncate text-slate-500">{{ e.user }}<template v-if="e.note"> · {{ e.note }}</template></span>
                <button v-if="e.can_edit" type="button" class="rounded p-0.5" :class="e.billable ? 'text-emerald-600' : 'text-slate-300'" :title="e.billable ? 'قابلة للفوترة' : 'غير قابلة للفوترة'" @click="toggleBillable(e)"><DollarSign class="size-3.5" /></button>
                <button v-if="e.can_edit" type="button" class="rounded p-0.5 text-slate-300 opacity-0 hover:text-red-600 group-hover:opacity-100" @click="remove(e)"><Trash2 class="size-3.5" /></button>
            </li>
        </ul>
        <p v-if="!time.entries.length" class="text-xs text-slate-500">لم يُسجَّل وقت بعد.</p>
    </div>
</template>

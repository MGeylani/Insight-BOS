<script setup>
// Gantt (frappe-gantt): bars from start to due, dependency arrows, the critical path, drag to move / extend.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import Gantt from 'frappe-gantt';
import '../../../../node_modules/frappe-gantt/dist/frappe-gantt.css';
import Btn from '@/components/Btn.vue';
import { patchTask, timePart, hasTime } from '@/lib/tasks';

const props = defineProps({ tasks: Array, can: Object, types: { type: Array, default: () => [] } });
const el = ref(null);
const mode = ref('Week');
const critical = ref(true);
let gantt = null;

const pad = (n) => String(n).padStart(2, '0');
const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const dated = computed(() => props.tasks.filter((t) => t.start || t.due));
const undated = computed(() => props.tasks.length - dated.value.length);
const milestoneTypes = computed(() => new Set(props.types.filter((t) => t.is_milestone).map((t) => t.id)));

/** From the task that finishes last, walk back through the blocker that finishes last. */
const criticalIds = computed(() => {
    const byId = new Map(dated.value.map((t) => [t.id, t]));
    const end = (t) => (t.due ?? t.start).slice(0, 10);
    let cur = [...byId.values()].filter((t) => !t.completed).sort((a, b) => end(b).localeCompare(end(a)))[0];
    const ids = new Set();
    for (let i = 0; cur && i < 200 && !ids.has(cur.id); i++) {
        ids.add(cur.id);
        cur = (cur.waiting_on ?? []).map((id) => byId.get(id)).filter(Boolean).sort((a, b) => end(b).localeCompare(end(a)))[0];
    }
    return ids.size > 1 ? ids : new Set();
});

const ganttTasks = () => dated.value.map((t) => {
    const start = (t.start && (!t.due || t.start <= t.due) ? t.start : t.due).slice(0, 10);
    const end = (t.due ?? t.start).slice(0, 10);
    const ids = new Set(dated.value.map((x) => x.id));
    const cls = [
        t.completed ? 'g-done' : '', t.overdue ? 'g-overdue' : '', milestoneTypes.value.has(t.type_id) ? 'g-milestone' : '',
        critical.value && criticalIds.value.has(t.id) ? 'g-critical' : '',
    ].filter(Boolean).join(' ');
    return {
        id: String(t.id), name: `${t.code} ${t.title}`, start, end,
        progress: t.completed ? 100 : t.children_count ? Math.round((t.children_done / t.children_count) * 100) : 0,
        dependencies: (t.waiting_on ?? []).filter((id) => ids.has(id)).map(String).join(','),
        custom_class: cls || undefined,
    };
});

function onDateChange(gt, start, end) {
    const t = props.tasks.find((x) => String(x.id) === gt.id);
    if (!t) return;
    const data = { due_date: ymd(end), due_time: hasTime(t.due) ? timePart(t.due) : null };
    if (t.start || ymd(start) !== ymd(end)) Object.assign(data, { start_date: ymd(start), start_time: hasTime(t.start) ? timePart(t.start) : null });
    patchTask(t.id, data, { onError: () => router.reload({ only: ['tasks'] }) });
}

function render() {
    if (!el.value) return;
    const list = ganttTasks();
    if (!list.length) { el.value.innerHTML = ''; gantt = null; return; }
    if (gantt) { gantt.refresh(list); return; }
    el.value.innerHTML = '';
    gantt = new Gantt(el.value, list, {
        view_mode: mode.value, language: 'ar', bar_height: 22, padding: 16, readonly: !props.can?.edit, readonly_progress: true,
        today_button: false, view_mode_select: false, scroll_to: 'today', popup_on: 'hover',
        on_date_change: onDateChange,
        on_double_click: (gt) => router.visit(`/tasks/${gt.id}`),
    });
}
onMounted(render);
watch(() => [props.tasks, critical.value], render, { deep: true });
watch(mode, (m) => gantt?.change_view_mode(m));
onBeforeUnmount(() => { gantt = null; });
</script>

<template>
    <div class="card p-4">
        <div class="mb-3 flex flex-wrap items-center gap-2 text-sm">
            <button v-for="m in [['Day', 'يوم'], ['Week', 'أسبوع'], ['Month', 'شهر']]" :key="m[0]" type="button" class="rounded-lg px-3 py-1" :class="mode === m[0] ? 'bg-navy text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'" @click="mode = m[0]">{{ m[1] }}</button>
            <label class="ms-3 flex items-center gap-1.5 text-slate-600"><input v-model="critical" type="checkbox" class="rounded" /> المسار الحرج</label>
            <span class="ms-auto text-xs text-slate-500">اسحب الشريط لتحريكه أو طرفه لتمديده · نقرتان لفتح المهمة<template v-if="undated"> · {{ undated }} مهمة بلا تواريخ لا تظهر</template></span>
        </div>
        <div dir="ltr" class="task-gantt overflow-x-auto"><div ref="el" /></div>
        <p v-if="!dated.length" class="py-10 text-center text-sm text-slate-500">لا مهام بتواريخ هنا. أضف تاريخ بدء أو تسليم لتظهر على المخطط.</p>
    </div>
</template>

<style>
.task-gantt .gantt .bar-wrapper .bar { fill: #0082f6; }
.task-gantt .gantt .bar-wrapper .bar-progress { fill: #00027c; }
.task-gantt .gantt .bar-wrapper.g-done .bar { fill: #10b981; }
.task-gantt .gantt .bar-wrapper.g-overdue .bar { fill: #ef4444; }
.task-gantt .gantt .bar-wrapper.g-critical .bar { stroke: #f59e0b; stroke-width: 3; }
.task-gantt .gantt .bar-wrapper.g-milestone .bar { fill: #481c4e; }
.task-gantt .gantt .bar-label { font-family: inherit; }
</style>

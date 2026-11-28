<script setup>
// Calendar (month / week / day): tasks on their due date (or start → due span). Drag to reschedule.
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import FullCalendar from '@fullcalendar/vue3';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import arLocale from '@fullcalendar/core/locales/ar';
import Modal from '@/components/Modal.vue';
import Btn from '@/components/Btn.vue';
import { parseLocal, hasTime, keep } from '@/lib/tasks';

const props = defineProps({ tasks: Array, can: Object, listId: Number, dateField: { type: String, default: 'due' }, extraEvents: { type: Array, default: () => [] } });
const emit = defineEmits(['range']);

const pad = (n) => String(n).padStart(2, '0');
const dateStr = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const timeStr = (d) => `${pad(d.getHours())}:${pad(d.getMinutes())}`;
const addDays = (s, n) => { const d = parseLocal(s); d.setDate(d.getDate() + n); return dateStr(d); };

const events = computed(() => [
    ...props.tasks.filter((t) => t[props.dateField] || (props.dateField === 'due' && t.start)).map((t) => {
        const end = t.due;
        const start = props.dateField === 'start' ? t.start : (t.start && t.due && t.start < t.due ? t.start : t.due ?? t.start);
        const allDay = !hasTime(start);
        const ev = {
            id: String(t.id), title: `${t.code} ${t.title}`, start, allDay,
            backgroundColor: t.status?.color ?? '#94a3b8', borderColor: t.overdue ? '#dc2626' : (t.status?.color ?? '#94a3b8'),
            textColor: '#fff', classNames: t.completed ? ['opacity-60', 'line-through'] : [],
            extendedProps: { task: t },
        };
        // A start → due span (end is exclusive for all-day events).
        if (props.dateField === 'due' && t.start && end && start !== end) ev.end = allDay && !hasTime(end) ? addDays(end, 1) : end;
        return ev;
    }),
    ...props.extraEvents,
]);

function reschedule(info) {
    const t = info.event.extendedProps.task;
    if (!t) { info.revert(); return; }
    const s = info.event.start;
    const e = info.event.end;
    const allDay = info.event.allDay;
    const data = {};
    if (props.dateField === 'start') {
        Object.assign(data, { start_date: dateStr(s), start_time: allDay ? null : timeStr(s) });
    } else if (t.start && e) {
        // Span: both ends move.
        const endDay = allDay ? new Date(e.getTime() - 86400000) : e;
        Object.assign(data, { start_date: dateStr(s), start_time: allDay ? null : timeStr(s), due_date: dateStr(endDay), due_time: allDay ? null : timeStr(e) });
    } else {
        Object.assign(data, { due_date: dateStr(s), due_time: allDay ? null : timeStr(s) });
        if (t.start && t.start > data.due_date) Object.assign(data, { start_date: data.due_date, start_time: null });
    }
    router.patch(`/tasks/${t.id}`, data, { ...keep, onError: () => info.revert() });
}

const quick = ref(false);
const quickDate = ref('');
const quickTitle = ref('');
function create() {
    if (!quickTitle.value.trim()) return;
    const [d, tm] = quickDate.value.split('T');
    router.post('/tasks', { title: quickTitle.value, task_list_id: props.listId, due_date: d, due_time: tm ?? null }, {
        ...keep, onSuccess: () => { quick.value = false; quickTitle.value = ''; },
    });
}

const options = computed(() => ({
    plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
    initialView: 'dayGridMonth',
    locale: arLocale,
    direction: 'rtl',
    firstDay: 6,
    height: 'auto',
    dayMaxEvents: 4,
    nowIndicator: true,
    headerToolbar: { start: 'title', center: '', end: 'today prev,next dayGridMonth,timeGridWeek,timeGridDay' },
    buttonText: { today: 'اليوم', month: 'شهر', week: 'أسبوع', day: 'يوم' },
    events: events.value,
    editable: Boolean(props.can?.edit),
    eventDurationEditable: props.dateField === 'due',
    eventDrop: reschedule,
    eventResize: reschedule,
    eventClick: (info) => {
        if (info.event.url) return; // extra events (appointments) open their own link
        info.jsEvent.preventDefault();
        router.visit(`/tasks/${info.event.id}`);
    },
    dateClick: (info) => {
        if (!props.can?.create || !props.listId) return;
        quickDate.value = info.allDay ? dateStr(info.date) : `${dateStr(info.date)}T${timeStr(info.date)}`;
        quick.value = true;
    },
    datesSet: (info) => emit('range', { start: dateStr(info.start), end: dateStr(info.end) }),
}));
</script>

<template>
    <div class="card p-4 task-calendar">
        <FullCalendar :options="options" />
        <p v-if="can?.create && !listId" class="mt-2 text-xs text-slate-500">للإضافة بالنقر على يوم، افتح قائمة محددة.</p>
        <Modal v-model="quick" title="مهمة في هذا التاريخ">
            <input v-model="quickTitle" class="field-input" placeholder="العنوان" autofocus @keydown.enter="create" />
            <p class="mt-2 text-xs text-slate-500" dir="ltr">{{ quickDate.replace('T', ' ') }}</p>
            <template #footer><Btn variant="secondary" @click="quick = false">إلغاء</Btn><Btn @click="create">إنشاء</Btn></template>
        </Modal>
    </div>
</template>

<style>
.task-calendar .fc { --fc-border-color: #e2e8f0; --fc-today-bg-color: rgb(0 190 242 / 0.07); --fc-button-bg-color: #00027c; --fc-button-border-color: #00027c; --fc-button-active-bg-color: #0082f6; --fc-button-hover-bg-color: #0082f6; font-size: 0.8rem; }
.task-calendar .fc .fc-toolbar-title { font-size: 1.1rem; font-weight: 700; color: #00027c; }
.task-calendar .fc-event { cursor: pointer; border-radius: 6px; padding: 1px 4px; }
</style>

<script setup>
// Manual time entry: date + duration ("1:30", "2س") or from/to.
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Btn from '@/components/Btn.vue';
import { parseMinutes, todayStr } from '@/lib/tasks';

const props = defineProps({ taskId: { type: Number, default: null }, tasks: { type: Array, default: null }, userId: { type: Number, default: null }, billableDefault: { type: Boolean, default: true } });
const emit = defineEmits(['done']);
const mode = ref('duration');
const duration = ref('');
const form = useForm({ task_id: props.taskId, date: todayStr(), minutes: null, from: '', to: '', billable: props.billableDefault, note: '', user_id: props.userId });
function save() {
    form.transform((d) => ({
        ...d, minutes: mode.value === 'duration' ? parseMinutes(duration.value) : null,
        from: mode.value === 'range' ? d.from : null, to: mode.value === 'range' ? d.to : null,
    })).post('/time-entries', { preserveScroll: true, onSuccess: () => { duration.value = ''; form.reset('note', 'from', 'to'); emit('done'); } });
}
</script>

<template>
    <div class="grid gap-2 rounded-xl bg-slate-50 p-3 text-sm sm:grid-cols-2">
        <select v-if="tasks" v-model="form.task_id" class="field-input py-1.5 sm:col-span-2"><option :value="null">بلا مهمة</option><option v-for="t in tasks" :key="t.id" :value="t.id">{{ t.label }}</option></select>
        <input v-model="form.date" type="date" dir="ltr" class="field-input py-1.5" />
        <div class="flex gap-1">
            <select v-model="mode" class="field-input w-24 py-1.5"><option value="duration">مدة</option><option value="range">من/إلى</option></select>
            <input v-if="mode === 'duration'" v-model="duration" class="field-input py-1.5" placeholder="1:30 أو 2س 15د" @keydown.enter="save" />
            <template v-else><input v-model="form.from" type="time" dir="ltr" class="field-input py-1.5" /><input v-model="form.to" type="time" dir="ltr" class="field-input py-1.5" /></template>
        </div>
        <input v-model="form.note" class="field-input py-1.5 sm:col-span-2" placeholder="ملاحظة (اختياري)" />
        <label class="flex items-center gap-2"><input v-model="form.billable" type="checkbox" class="rounded" /> قابلة للفوترة</label>
        <div class="flex justify-end"><Btn size="sm" :loading="form.processing" @click="save">تسجيل</Btn></div>
        <p v-for="(e, k) in form.errors" :key="k" class="field-error sm:col-span-2">{{ e }}</p>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { BellPlus } from '@lucide/vue';
import Dropdown from '@/components/Dropdown.vue';
import { todayStr } from '@/lib/tasks';

const props = defineProps({ taskId: { type: Number, default: null }, label: { type: String, default: 'ذكّرني' } });
const at = ref('');
const note = ref('');
const pad = (n) => String(n).padStart(2, '0');
const inHours = (h) => { const d = new Date(Date.now() + h * 3600000); return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`; };
function save(value, close) {
    if (!value) return;
    router.post('/reminders', { task_id: props.taskId, remind_at: value, note: note.value || null }, {
        preserveScroll: true, preserveState: true, onSuccess: () => { at.value = ''; note.value = ''; close(); },
    });
}
</script>

<template>
    <Dropdown width="w-64">
        <template #trigger><slot><button type="button" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"><BellPlus class="size-4" /> {{ label }}</button></slot></template>
        <template #default="{ close }">
            <div class="space-y-2 p-2">
                <div class="flex flex-wrap gap-1">
                    <button type="button" class="rounded-lg bg-slate-100 px-2 py-1 text-xs hover:bg-slate-200" @click="save(inHours(1), close)">بعد ساعة</button>
                    <button type="button" class="rounded-lg bg-slate-100 px-2 py-1 text-xs hover:bg-slate-200" @click="save(inHours(3), close)">بعد 3 ساعات</button>
                    <button type="button" class="rounded-lg bg-slate-100 px-2 py-1 text-xs hover:bg-slate-200" @click="save(`${todayStr(1)}T09:00`, close)">غداً 9:00</button>
                </div>
                <input v-model="at" type="datetime-local" dir="ltr" class="field-input py-1.5 text-sm" />
                <input v-model="note" class="field-input py-1.5 text-sm" placeholder="ملاحظة (اختياري)" maxlength="255" />
                <button type="button" class="w-full rounded-lg bg-navy py-1.5 text-xs font-semibold text-white disabled:opacity-40" :disabled="!at" @click="save(at, close)">حفظ التذكير</button>
            </div>
        </template>
    </Dropdown>
</template>

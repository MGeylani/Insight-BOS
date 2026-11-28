<script setup>
// Date with an optional time. Value: "YYYY-MM-DD" or "YYYY-MM-DDTHH:mm" (local), or null.
import { ref, watch } from 'vue';
import { CalendarDays, X } from '@lucide/vue';
import Dropdown from '@/components/Dropdown.vue';
import { dueLabel, datePart, timePart, todayStr } from '@/lib/tasks';

const props = defineProps({ modelValue: String, editable: Boolean, overdue: Boolean, placeholder: { type: String, default: 'التاريخ' } });
const emit = defineEmits(['update:modelValue']);
const date = ref('');
const time = ref('');
const sync = () => { date.value = datePart(props.modelValue); time.value = timePart(props.modelValue); };
watch(() => props.modelValue, sync, { immediate: true });

function apply(close) {
    const v = date.value ? (time.value ? `${date.value}T${time.value}` : date.value) : null;
    if (v !== (props.modelValue ?? null)) emit('update:modelValue', v);
    close();
}
function quick(offset, close) { date.value = todayStr(offset); apply(close); }
function clear(close) { date.value = ''; time.value = ''; apply(close); }
</script>

<template>
    <Dropdown :disabled="!editable" width="w-64" @close="sync">
        <template #trigger>
            <button type="button" class="inline-flex items-center gap-1 rounded-md px-1 py-0.5 text-xs disabled:cursor-default"
                :class="[modelValue ? (overdue ? 'font-semibold text-red-600' : 'text-slate-700') : 'text-slate-400', editable ? 'hover:bg-slate-100' : '']" :disabled="!editable">
                <CalendarDays v-if="!modelValue" class="size-3.5" />
                {{ modelValue ? dueLabel(modelValue) : '' }}
                <span v-if="!modelValue && placeholder" class="hidden sm:inline">{{ placeholder }}</span>
            </button>
        </template>
        <template #default="{ close }">
            <div class="space-y-2 p-2">
                <div class="flex flex-wrap gap-1">
                    <button type="button" class="rounded-lg bg-slate-100 px-2 py-1 text-xs hover:bg-slate-200" @click="quick(0, close)">اليوم</button>
                    <button type="button" class="rounded-lg bg-slate-100 px-2 py-1 text-xs hover:bg-slate-200" @click="quick(1, close)">غداً</button>
                    <button type="button" class="rounded-lg bg-slate-100 px-2 py-1 text-xs hover:bg-slate-200" @click="quick(7, close)">بعد أسبوع</button>
                </div>
                <input v-model="date" type="date" dir="ltr" class="field-input py-1.5 text-sm" />
                <input v-model="time" type="time" dir="ltr" class="field-input py-1.5 text-sm" :disabled="!date" />
                <div class="flex justify-between gap-2">
                    <button type="button" class="flex items-center gap-1 text-xs text-slate-500 hover:text-red-600" @click="clear(close)"><X class="size-3.5" /> بلا تاريخ</button>
                    <button type="button" class="rounded-lg bg-navy px-3 py-1 text-xs font-semibold text-white" @click="apply(close)">تطبيق</button>
                </div>
            </div>
        </template>
    </Dropdown>
</template>

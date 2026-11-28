<script setup>
import { ref, watch } from 'vue';
import { Repeat } from '@lucide/vue';
import Dropdown from '@/components/Dropdown.vue';

const props = defineProps({ modelValue: Object, label: String, editable: Boolean });
const emit = defineEmits(['update:modelValue']);
const days = ['ح', 'ن', 'ث', 'ر', 'خ', 'ج', 'س'];
const r = ref({});
const reset = () => { r.value = { freq: '', interval: 1, weekdays: [], on: 'complete', until: '', ...(props.modelValue ?? {}) }; };
watch(() => props.modelValue, reset, { immediate: true });
const toggleDay = (d) => { r.value.weekdays = r.value.weekdays.includes(d) ? r.value.weekdays.filter((x) => x !== d) : [...r.value.weekdays, d]; };
const apply = (close) => { emit('update:modelValue', r.value.freq ? { ...r.value, until: r.value.until || null } : null); close(); };
const clear = (close) => { emit('update:modelValue', null); close(); };
</script>

<template>
    <Dropdown :disabled="!editable" width="w-72" @close="reset">
        <template #trigger>
            <button type="button" class="inline-flex items-center gap-1 rounded-md px-1 py-0.5 text-xs disabled:cursor-default" :class="[modelValue ? 'text-plum' : 'text-slate-400', editable ? 'hover:bg-slate-100' : '']" :disabled="!editable">
                <Repeat class="size-3.5" /> {{ modelValue ? label : 'لا تتكرر' }}
            </button>
        </template>
        <template #default="{ close }">
            <div class="space-y-3 p-2 text-sm">
                <div class="flex items-center gap-2">
                    <span class="text-xs text-slate-500">كل</span>
                    <input v-model.number="r.interval" type="number" min="1" max="365" class="field-input w-16 py-1" />
                    <select v-model="r.freq" class="field-input flex-1 py-1">
                        <option value="">— لا تتكرر —</option><option value="daily">يوم</option><option value="weekly">أسبوع</option><option value="monthly">شهر</option><option value="yearly">سنة</option>
                    </select>
                </div>
                <div v-if="r.freq === 'weekly'" class="flex gap-1">
                    <button v-for="(d, i) in days" :key="i" type="button" class="size-7 rounded-full text-xs" :class="r.weekdays.includes(i) ? 'bg-navy text-white' : 'bg-slate-100 text-slate-600'" @click="toggleDay(i)">{{ d }}</button>
                </div>
                <div v-if="r.freq">
                    <label class="flex items-center gap-2"><input v-model="r.on" type="radio" value="complete" /> النسخة التالية عند إغلاق هذه</label>
                    <label class="flex items-center gap-2"><input v-model="r.on" type="radio" value="schedule" /> بالجدول (في موعد التسليم ولو لم تُغلق)</label>
                </div>
                <label v-if="r.freq" class="block"><span class="text-xs text-slate-500">حتى (اختياري)</span><input v-model="r.until" type="date" dir="ltr" class="field-input py-1" /></label>
                <p v-if="r.freq" class="text-[11px] text-slate-500">تحتاج المهمة تاريخ تسليم ليُحسب موعد النسخة التالية.</p>
                <div class="flex justify-between">
                    <button v-if="modelValue" type="button" class="text-xs text-red-600 hover:underline" @click="clear(close)">إلغاء التكرار</button>
                    <button type="button" class="ms-auto rounded-lg bg-navy px-3 py-1 text-xs font-semibold text-white" @click="apply(close)">تطبيق</button>
                </div>
            </div>
        </template>
    </Dropdown>
</template>

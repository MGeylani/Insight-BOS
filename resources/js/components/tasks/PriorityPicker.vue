<script setup>
import { Flag, Check } from '@lucide/vue';
import Dropdown from '@/components/Dropdown.vue';
import { priorityColor } from '@/lib/tasks';

const props = defineProps({ modelValue: String, priorities: Object, editable: Boolean, label: Boolean });
const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <Dropdown :disabled="!editable" width="w-40">
        <template #trigger>
            <button type="button" class="inline-flex items-center gap-1 rounded-md px-1 py-0.5 text-xs hover:bg-slate-100 disabled:cursor-default disabled:hover:bg-transparent" :disabled="!editable" :title="priorities[modelValue]">
                <Flag class="size-4" :style="{ color: priorityColor[modelValue] ?? '#cbd5e1' }" :fill="modelValue === 'urgent' || modelValue === 'high' ? priorityColor[modelValue] : 'none'" />
                <span v-if="label" class="text-slate-700">{{ priorities[modelValue] }}</span>
            </button>
        </template>
        <template #default="{ close }">
            <button v-for="(l, k) in priorities" :key="k" type="button" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50" @click="emit('update:modelValue', k); close()">
                <Flag class="size-4" :style="{ color: priorityColor[k] }" />
                <span class="flex-1 text-start">{{ l }}</span>
                <Check v-if="modelValue === k" class="size-4 text-brand" />
            </button>
        </template>
    </Dropdown>
</template>

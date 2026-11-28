<script setup>
// Pick people for an automation: specific users and/or roles relative to the task.
import { computed } from 'vue';
import { Check } from '@lucide/vue';
import Dropdown from '@/components/Dropdown.vue';

const props = defineProps({ modelValue: { type: Array, default: () => [] }, users: Array, roles: { type: Boolean, default: true } });
const emit = defineEmits(['update:modelValue']);
const keywords = { assignees: 'مسؤولو المهمة', watchers: 'متابعو المهمة', creator: 'منشئ المهمة', project_manager: 'مدير المشروع' };
const label = computed(() => props.modelValue.map((v) => keywords[v] ?? props.users.find((u) => u.id === Number(v))?.name ?? v).join('، ') || 'اختر…');
const toggle = (v) => emit('update:modelValue', props.modelValue.includes(v) ? props.modelValue.filter((x) => x !== v) : [...props.modelValue, v]);
</script>

<template>
    <Dropdown width="w-60">
        <template #trigger><button type="button" class="field-input truncate py-1.5 text-start text-sm">{{ label }}</button></template>
        <template v-if="roles"><button v-for="(l, k) in keywords" :key="k" type="button" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50" @click="toggle(k)"><span class="flex-1 text-start">{{ l }}</span><Check v-if="modelValue.includes(k)" class="size-4 text-brand" /></button><hr class="my-1" /></template>
        <button v-for="u in users" :key="u.id" type="button" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50" @click="toggle(u.id)"><span class="flex-1 text-start">{{ u.name }}</span><Check v-if="modelValue.includes(u.id)" class="size-4 text-brand" /></button>
    </Dropdown>
</template>

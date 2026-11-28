<script setup>
import { computed, ref } from 'vue';
import { UserPlus, Check } from '@lucide/vue';
import Dropdown from '@/components/Dropdown.vue';
import Avatar from '@/components/Avatar.vue';

const props = defineProps({ modelValue: { type: Array, default: () => [] }, users: Array, editable: Boolean, max: { type: Number, default: 3 }, placeholder: String });
const emit = defineEmits(['update:modelValue']);
const q = ref('');
const selected = computed(() => props.modelValue.map((id) => props.users.find((u) => u.id === id) ?? { id, name: '؟' }));
const filtered = computed(() => props.users.filter((u) => !q.value || u.name.includes(q.value)));
function toggle(id) {
    const ids = props.modelValue.includes(id) ? props.modelValue.filter((x) => x !== id) : [...props.modelValue, id];
    emit('update:modelValue', ids);
}
</script>

<template>
    <Dropdown :disabled="!editable" width="w-60" @close="q = ''">
        <template #trigger>
            <button type="button" class="flex items-center disabled:cursor-default" :disabled="!editable" :title="selected.map((u) => u.name).join('، ')">
                <template v-if="selected.length">
                    <span class="flex -space-x-1.5 space-x-reverse"><Avatar v-for="u in selected.slice(0, max)" :key="u.id" :name="u.name" /></span>
                    <span v-if="selected.length > max" class="num ms-1 text-xs text-slate-500">+{{ selected.length - max }}</span>
                </template>
                <span v-else class="flex items-center gap-1 rounded-full border border-dashed border-slate-300 p-1 text-slate-400 hover:border-brand hover:text-brand">
                    <UserPlus class="size-3.5" /><span v-if="placeholder" class="text-xs">{{ placeholder }}</span>
                </span>
            </button>
        </template>
        <input v-model="q" class="field-input mb-1 py-1.5 text-sm" placeholder="بحث…" />
        <button v-for="u in filtered" :key="u.id" type="button" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50" @click="toggle(u.id)">
            <Avatar :name="u.name" />
            <span class="flex-1 truncate text-start">{{ u.name }}</span>
            <Check v-if="modelValue.includes(u.id)" class="size-4 text-brand" />
        </button>
    </Dropdown>
</template>

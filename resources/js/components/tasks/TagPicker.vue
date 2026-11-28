<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Tag as TagIcon, Check, Plus } from '@lucide/vue';
import Dropdown from '@/components/Dropdown.vue';

const props = defineProps({ modelValue: { type: Array, default: () => [] }, tags: Array, editable: Boolean, canCreate: Boolean });
const emit = defineEmits(['update:modelValue']);
const q = ref('');
const selected = computed(() => props.tags.filter((t) => props.modelValue.includes(t.id)));
const filtered = computed(() => props.tags.filter((t) => !q.value || t.name.includes(q.value)));
const exact = computed(() => props.tags.some((t) => t.name === q.value.trim()));
function toggle(id) {
    emit('update:modelValue', props.modelValue.includes(id) ? props.modelValue.filter((x) => x !== id) : [...props.modelValue, id]);
}
function create() {
    const name = q.value.trim();
    if (!name) return;
    router.post('/tasks/tags', { name }, {
        preserveScroll: true, preserveState: true,
        onSuccess: (page) => {
            const t = (page.props.tags ?? []).find((x) => x.name === name);
            if (t) toggle(t.id);
            q.value = '';
        },
    });
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-1">
        <span v-for="t in selected" :key="t.id" class="rounded-md px-1.5 py-0.5 text-[11px] font-medium" :style="{ background: t.color + '22', color: t.color }">{{ t.name }}</span>
        <Dropdown v-if="editable" width="w-56" @close="q = ''">
            <template #trigger>
                <button type="button" class="rounded-md p-0.5 text-slate-400 hover:bg-slate-100 hover:text-brand" title="الوسوم"><TagIcon class="size-3.5" /></button>
            </template>
            <input v-model="q" class="field-input mb-1 py-1.5 text-sm" placeholder="بحث أو وسم جديد…" @keydown.enter.prevent="!exact && canCreate && create()" />
            <button v-for="t in filtered" :key="t.id" type="button" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50" @click="toggle(t.id)">
                <span class="size-2.5 rounded-full" :style="{ background: t.color }" />
                <span class="flex-1 truncate text-start">{{ t.name }}</span>
                <Check v-if="modelValue.includes(t.id)" class="size-4 text-brand" />
            </button>
            <button v-if="canCreate && q.trim() && !exact" type="button" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-brand hover:bg-slate-50" @click="create">
                <Plus class="size-4" /> إنشاء «{{ q.trim() }}»
            </button>
            <p v-if="!filtered.length && !canCreate" class="px-2 py-2 text-xs text-slate-500">لا توجد وسوم.</p>
        </Dropdown>
    </div>
</template>

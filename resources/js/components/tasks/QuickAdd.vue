<script setup>
import { ref, nextTick, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';

const props = defineProps({ listId: Number, statusId: Number, parentId: Number, placeholder: { type: String, default: 'مهمة جديدة… (Enter للحفظ)' }, autofocus: Boolean, indent: { type: Number, default: 0 }, extra: Object });
const emit = defineEmits(['done']);
const title = ref('');
const busy = ref(false);
const input = ref(null);
onMounted(() => props.autofocus && nextTick(() => input.value?.focus()));

function save() {
    const t = title.value.trim();
    if (!t || busy.value) return;
    busy.value = true;
    router.post('/tasks', { title: t, task_list_id: props.listId, status_id: props.statusId, parent_id: props.parentId, ...(props.extra ?? {}) }, {
        preserveScroll: true, preserveState: true,
        onSuccess: () => { title.value = ''; nextTick(() => input.value?.focus()); },
        onFinish: () => (busy.value = false),
    });
}
</script>

<template>
    <div class="flex items-center gap-2 border-b border-slate-100 px-3 py-1.5" :style="{ paddingInlineStart: 12 + indent * 22 + 'px' }">
        <Plus class="size-4 shrink-0 text-slate-300" />
        <input ref="input" v-model="title" :placeholder="placeholder" class="w-full bg-transparent py-0.5 text-sm outline-none placeholder:text-slate-400"
            :disabled="busy" @keydown.enter.prevent="save" @keydown.esc="emit('done')" @blur="!title && emit('done')" />
    </div>
</template>

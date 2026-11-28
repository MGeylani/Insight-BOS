<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Plus, Trash2, CornerDownLeft } from '@lucide/vue';
import { keep } from '@/lib/tasks';

defineOptions({ name: 'ChecklistItems' });
const props = defineProps({ checklistId: Number, items: Array, parentId: { type: Number, default: null }, users: Array, editable: Boolean, level: Number });
const rows = computed(() => props.items.filter((i) => (i.parent_id ?? null) === props.parentId));
const text = ref('');
const addingFor = ref(null);
const add = (parentId) => {
    if (!text.value.trim()) return;
    router.post(`/tasks/checklists/${props.checklistId}/items`, { text: text.value, parent_id: parentId }, { ...keep, onSuccess: () => (text.value = '') });
};
const patch = (i, data) => router.patch(`/tasks/checklist-items/${i.id}`, data, keep);
const remove = (i) => router.delete(`/tasks/checklist-items/${i.id}`, keep);
</script>

<template>
    <ul class="space-y-0.5" :class="level ? 'ms-6' : ''">
        <li v-for="i in rows" :key="i.id">
            <div class="group flex items-center gap-2 rounded-lg px-1 py-0.5 hover:bg-slate-50">
                <input type="checkbox" class="size-4 rounded border-slate-300 text-emerald-600" :checked="i.done" :disabled="!editable" @change="patch(i, { done: !i.done })" />
                <input :value="i.text" class="min-w-0 flex-1 bg-transparent text-sm outline-none" :class="i.done ? 'text-slate-400 line-through' : 'text-slate-700'" :readonly="!editable"
                    @change="$event.target.value.trim() && patch(i, { text: $event.target.value })" />
                <select v-if="editable" :value="i.assignee_id ?? ''" class="w-24 rounded border-0 bg-transparent py-0 text-xs text-slate-500 opacity-60 group-hover:opacity-100" @change="patch(i, { assignee_id: $event.target.value || null })">
                    <option value="">—</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
                <span v-else-if="i.assignee" class="text-xs text-slate-500">{{ i.assignee }}</span>
                <button v-if="editable && level < 2" type="button" class="rounded p-0.5 text-slate-400 opacity-0 hover:text-brand group-hover:opacity-100" title="بند فرعي" @click="addingFor = i.id; text = ''"><Plus class="size-3.5" /></button>
                <button v-if="editable" type="button" class="rounded p-0.5 text-slate-400 opacity-0 hover:text-red-600 group-hover:opacity-100" @click="remove(i)"><Trash2 class="size-3.5" /></button>
            </div>
            <ChecklistItems :checklist-id="checklistId" :items="items" :parent-id="i.id" :users="users" :editable="editable" :level="level + 1" />
            <div v-if="addingFor === i.id" class="ms-6 flex items-center gap-2 py-1">
                <input v-model="text" class="field-input flex-1 py-1 text-sm" placeholder="بند فرعي…" autofocus @keydown.enter="add(i.id)" @keydown.esc="addingFor = null" />
                <button type="button" class="rounded p-1 text-slate-500 hover:bg-slate-100" @click="add(i.id)"><CornerDownLeft class="size-4" /></button>
            </div>
        </li>
        <li v-if="editable && level === 0" class="flex items-center gap-2 px-1 py-1">
            <Plus class="size-4 text-slate-300" />
            <input v-if="addingFor === null" v-model="text" class="flex-1 bg-transparent text-sm outline-none placeholder:text-slate-400" placeholder="بند جديد… (Enter)" @keydown.enter="add(null)" />
            <input v-else class="flex-1 bg-transparent text-sm outline-none placeholder:text-slate-400" placeholder="بند جديد… (Enter)" @focus="addingFor = null" />
        </li>
    </ul>
</template>

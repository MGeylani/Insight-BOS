<script setup>
// Spreadsheet-like table: chosen columns, inline cell edits, row selection for bulk changes.
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp } from '@lucide/vue';
import StatusPill from './StatusPill.vue';
import PeoplePicker from './PeoplePicker.vue';
import DueInput from './DueInput.vue';
import PriorityPicker from './PriorityPicker.vue';
import TagPicker from './TagPicker.vue';
import { patchTask, dateParams, minutesLabel, parseMinutes, columnLabels } from '@/lib/tasks';
import { formatDate } from '@/lib/format';

const props = defineProps({
    tasks: Array, statuses: Array, users: Array, tags: Array, priorities: Object, can: Object,
    columns: Array, sort: String, dir: String, selected: { type: Array, default: () => [] },
});
const emit = defineEmits(['toggle-select', 'select-many', 'sort']);
const sortable = { due: 'due', priority: 'priority', created: 'created', title: 'title' };
const editingTitle = ref(null);
const titleDraft = ref('');
const allSelected = () => props.tasks.length && props.tasks.every((t) => props.selected.includes(t.id));
const startTitle = (t) => { if (!props.can?.edit) return; editingTitle.value = t.id; titleDraft.value = t.title; };
const saveTitle = (t) => { if (titleDraft.value.trim() && titleDraft.value !== t.title) patchTask(t.id, { title: titleDraft.value }); editingTitle.value = null; };
const saveEstimate = (t, v) => { const m = parseMinutes(v); if (m !== t.estimate) patchTask(t.id, { time_estimate_min: m }); };
const sortBy = (col) => sortable[col] && emit('sort', sortable[col], props.sort === sortable[col] && props.dir === 'asc' ? 'desc' : 'asc');
</script>

<template>
    <div class="card overflow-x-auto">
        <table class="w-full min-w-[56rem] text-sm">
            <thead class="sticky top-0 z-10 bg-slate-50 text-xs text-slate-500">
                <tr class="border-b border-slate-200">
                    <th class="w-8 px-2 py-2"><input type="checkbox" class="size-3.5 rounded border-slate-300" :checked="allSelected()" @change="emit('select-many', tasks.map((t) => t.id), !allSelected())" /></th>
                    <th class="px-2 py-2 text-start font-medium"><button type="button" class="flex items-center gap-1 hover:text-navy" @click="sortBy('title')">المهمة <ArrowUp v-if="sort === 'title' && dir === 'asc'" class="size-3" /><ArrowDown v-else-if="sort === 'title'" class="size-3" /></button></th>
                    <th v-for="c in columns" :key="c" class="whitespace-nowrap px-2 py-2 text-start font-medium">
                        <button type="button" class="flex items-center gap-1" :class="sortable[c] ? 'hover:text-navy' : 'cursor-default'" @click="sortBy(c)">
                            {{ columnLabels[c] }} <ArrowUp v-if="sort === sortable[c] && dir === 'asc'" class="size-3" /><ArrowDown v-else-if="sortable[c] && sort === sortable[c]" class="size-3" />
                        </button>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="t in tasks" :key="t.id" class="border-b border-slate-100 hover:bg-slate-50/70" :class="{ 'bg-brand/5': selected.includes(t.id) }">
                    <td class="px-2 py-1.5"><input type="checkbox" class="size-3.5 rounded border-slate-300" :checked="selected.includes(t.id)" @change="emit('toggle-select', t.id)" /></td>
                    <td class="max-w-[22rem] px-2 py-1.5" :style="{ paddingInlineStart: 8 + t.depth * 16 + 'px' }">
                        <input v-if="editingTitle === t.id" v-model="titleDraft" class="field-input py-1 text-sm" autofocus @blur="saveTitle(t)" @keydown.enter="$event.target.blur()" @keydown.esc="editingTitle = null" />
                        <div v-else class="flex items-center gap-1.5" @dblclick="startTitle(t)">
                            <span class="num shrink-0 text-[11px] text-slate-400" dir="ltr">{{ t.code }}</span>
                            <Link :href="`/tasks/${t.id}`" class="truncate font-medium hover:text-brand" :class="t.completed ? 'text-slate-400 line-through' : 'text-slate-800'">{{ t.title }}</Link>
                        </div>
                    </td>
                    <td v-for="c in columns" :key="c" class="whitespace-nowrap px-2 py-1.5">
                        <StatusPill v-if="c === 'status'" :status="t.status" :statuses="statuses" :editable="can?.edit" @change="(s) => patchTask(t.id, { status_id: s.id })" />
                        <PeoplePicker v-else-if="c === 'assignees'" :model-value="t.assignees.map((u) => u.id)" :users="users" :editable="can?.assign" @update:model-value="(v) => patchTask(t.id, { assignees: v })" />
                        <DueInput v-else-if="c === 'due'" :model-value="t.due" :overdue="t.overdue" :editable="can?.edit" placeholder="" @update:model-value="(v) => patchTask(t.id, dateParams('due', v))" />
                        <DueInput v-else-if="c === 'start'" :model-value="t.start" :editable="can?.edit" placeholder="" @update:model-value="(v) => patchTask(t.id, dateParams('start', v))" />
                        <PriorityPicker v-else-if="c === 'priority'" :model-value="t.priority" :priorities="priorities" :editable="can?.edit" label @update:model-value="(v) => patchTask(t.id, { priority: v })" />
                        <input v-else-if="c === 'estimate'" :value="minutesLabel(t.estimate)" class="w-20 rounded border border-transparent bg-transparent px-1 py-0.5 text-xs hover:border-slate-200 focus:border-brand focus:outline-none" :readonly="!can?.edit" placeholder="—" @change="saveEstimate(t, $event.target.value)" />
                        <TagPicker v-else-if="c === 'tags'" :model-value="t.tags.map((x) => x.id)" :tags="tags" :editable="can?.edit" :can-create="can?.edit" @update:model-value="(v) => patchTask(t.id, { tags: v })" />
                        <span v-else-if="c === 'list'" class="text-xs text-slate-600">{{ t.list?.name }}</span>
                        <span v-else-if="c === 'client'" class="text-xs text-slate-600">{{ t.client?.name }}</span>
                        <span v-else-if="c === 'project'" class="text-xs text-slate-600">{{ t.project?.name ?? '—' }}</span>
                        <span v-else-if="c === 'created'" class="text-xs text-slate-500">{{ formatDate(t.created_at) }}</span>
                        <span v-else-if="c === 'completed'" class="text-xs text-slate-500">{{ t.completed_at ? formatDate(t.completed_at) : '—' }}</span>
                        <span v-else-if="c === 'subtasks'" class="num text-xs text-slate-500">{{ t.children_count ? `${t.children_done}/${t.children_count}` : '—' }}</span>
                        <span v-else-if="c === 'comments'" class="num text-xs text-slate-500">{{ t.comments_count || '—' }}</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

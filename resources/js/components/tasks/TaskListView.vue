<script setup>
// List view: tasks grouped by any field, subtasks nested under their parent, inline editing.
import { computed, reactive, ref } from 'vue';
import { ChevronDown, ChevronLeft, Plus } from '@lucide/vue';
import TaskRow from './TaskRow.vue';
import QuickAdd from './QuickAdd.vue';
import { buildTree, groupTasks } from '@/lib/tasks';

const props = defineProps({
    tasks: Array, statuses: Array, users: Array, tags: Array, priorities: Object, can: Object,
    listId: Number, showLocation: Boolean, selectable: Boolean, selected: { type: Array, default: () => [] },
    groupBy: { type: String, default: 'status' }, types: { type: Array, default: () => [] }, customFields: { type: Array, default: () => [] },
});
const emit = defineEmits(['toggle-select', 'select-many']);
const collapsed = reactive({});
const adding = ref(null); // group key with an open quick-add
const subFor = ref(null); // task id with an open subtask quick-add

const tree = computed(() => buildTree(props.tasks));
const groups = computed(() => groupTasks(tree.value, props.groupBy, props)
    .filter((g) => g.rows.length || (props.groupBy === 'status' && props.listId && g.status?.group !== 'closed')));
const allIds = (rows) => rows.flatMap((r) => [r.id, ...allIds(r.children ?? [])]);
const groupSelected = (g) => g.rows.length && allIds(g.rows).every((id) => props.selected.includes(id));
</script>

<template>
    <div class="space-y-4">
        <div v-for="g in groups" :key="g.key" class="card overflow-hidden">
            <div class="flex items-center gap-2 border-b border-slate-100 px-3 py-2">
                <input v-if="selectable" type="checkbox" class="size-3.5 rounded border-slate-300" :checked="groupSelected(g)" title="تحديد المجموعة"
                    @change="emit('select-many', allIds(g.rows), !groupSelected(g))" />
                <button type="button" class="rounded p-0.5 text-slate-400 hover:bg-slate-100" @click="collapsed[g.key] = !collapsed[g.key]">
                    <ChevronLeft v-if="collapsed[g.key]" class="size-4" /><ChevronDown v-else class="size-4" />
                </button>
                <span class="rounded-md px-2 py-0.5 text-xs font-bold text-white" :style="{ background: g.color }">{{ g.label }}</span>
                <span class="num text-xs text-slate-400">{{ g.rows.length }}</span>
                <button v-if="can?.create && listId && g.status" type="button" class="ms-auto flex items-center gap-1 rounded-lg px-2 py-1 text-xs text-slate-500 hover:bg-slate-100 hover:text-brand" @click="adding = g.key; collapsed[g.key] = false">
                    <Plus class="size-3.5" /> مهمة
                </button>
            </div>
            <template v-if="!collapsed[g.key]">
                <div class="hidden items-center gap-2 border-b border-slate-100 bg-slate-50/60 px-3 py-1 text-[11px] text-slate-400 sm:flex">
                    <span v-if="selectable" class="w-3.5" /><span class="flex-1">الاسم</span><span class="w-28">الحالة</span><span class="w-20">المسؤول</span><span class="w-24">التسليم</span><span class="w-8">أولوية</span><span class="w-14">التقدير</span>
                </div>
                <TaskRow v-for="t in g.rows" :key="t.id" :task="t" :statuses="statuses" :users="users" :tags="tags" :priorities="priorities" :can="can" :types="types"
                    :show-location="showLocation" :selectable="selectable" :selected="selected" @toggle-select="(id) => emit('toggle-select', id)" @add-subtask="(x) => (subFor = x.id)">
                    <template #after="{ task }">
                        <QuickAdd v-if="subFor === task.id" :parent-id="task.id" :indent="task.depth + 1" autofocus placeholder="مهمة فرعية… (Enter للحفظ)" @done="subFor = null" />
                    </template>
                </TaskRow>
                <QuickAdd v-if="adding === g.key" :list-id="listId" :status-id="g.status?.id" autofocus @done="adding = null" />
                <p v-if="!g.rows.length && adding !== g.key" class="px-4 py-3 text-xs text-slate-400">لا مهام.</p>
            </template>
        </div>
    </div>
</template>

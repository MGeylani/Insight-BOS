<script setup>
// Kanban board: columns by status (or priority / assignee / due date); drag a card to change it.
import { ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { VueDraggable } from 'vue-draggable-plus';
import { GitBranch, MessageSquare, Plus } from '@lucide/vue';
import PeoplePicker from './PeoplePicker.vue';
import DueInput from './DueInput.vue';
import PriorityPicker from './PriorityPicker.vue';
import QuickAdd from './QuickAdd.vue';
import TypeIcon from './TypeIcon.vue';
import { groupTasks, patchTask, dateParams, keep } from '@/lib/tasks';

const props = defineProps({
    tasks: Array, statuses: Array, users: Array, tags: Array, priorities: Object, can: Object,
    listId: Number, groupBy: { type: String, default: 'status' }, manual: Boolean,
    types: { type: Array, default: () => [] }, customFields: { type: Array, default: () => [] },
});
const cols = ref([]);
watch(() => [props.tasks, props.groupBy, props.statuses], () => {
    const top = props.tasks.filter((t) => !t.parent_id || !props.tasks.some((p) => p.id === t.parent_id));
    cols.value = groupTasks(top, props.groupBy, props).map((g) => ({ ...g, rows: [...g.rows] }));
}, { immediate: true, deep: true });
const adding = ref(null);

function onEnd(evt) {
    const id = Number(evt.item?.dataset?.taskId);
    const from = cols.value.find((c) => c.key === evt.from?.dataset?.group);
    const to = cols.value.find((c) => c.key === evt.to?.dataset?.group);
    if (!id || !to) return;
    const task = props.tasks.find((t) => t.id === id);
    if (from?.key !== to.key) {
        if (to.fieldDrop) { router.put(`/tasks/${id}/fields/${to.fieldDrop.field}`, { value: to.fieldDrop.value }, keep); return; }
        const payload = to.drop?.(task, from?.key);
        if (!payload) { router.reload({ only: ['tasks'] }); return; } // not a droppable column
        patchTask(id, payload, { onError: () => router.reload({ only: ['tasks'] }) });
    } else if (props.manual && evt.oldIndex !== evt.newIndex) {
        const before = to.rows[evt.newIndex - 1]?.position ?? null;
        const after = to.rows[evt.newIndex + 1]?.position ?? null;
        router.post(`/tasks/${id}/reorder`, { before, after }, keep);
    }
}
</script>

<template>
    <div class="flex gap-4 overflow-x-auto pb-4">
        <div v-for="c in cols" :key="c.key" class="flex w-72 shrink-0 flex-col rounded-2xl bg-slate-100/70">
            <div class="flex items-center gap-2 px-3 py-2.5">
                <span class="size-2.5 rounded-full" :style="{ background: c.color }" />
                <span class="truncate text-sm font-bold text-slate-700">{{ c.label }}</span>
                <span class="num text-xs text-slate-400">{{ c.rows.length }}</span>
                <button v-if="can?.create && listId && c.status" type="button" class="ms-auto rounded p-1 text-slate-400 hover:bg-white hover:text-brand" title="مهمة" @click="adding = c.key"><Plus class="size-4" /></button>
            </div>
            <div v-if="adding === c.key" class="mx-2 mb-2 overflow-hidden rounded-xl bg-white">
                <QuickAdd :list-id="listId" :status-id="c.status?.id" autofocus @done="adding = null" />
            </div>
            <VueDraggable v-model="c.rows" :data-group="c.key" group="board" :animation="150" :disabled="!can?.edit" ghost-class="opacity-40" filter=".no-drag" :prevent-on-filter="false"
                class="flex min-h-24 flex-1 flex-col gap-2 px-2 pb-3" @end="onEnd">
                <div v-for="t in c.rows" :key="t.id" :data-task-id="t.id" class="cursor-grab rounded-xl border border-slate-200 bg-white p-3 shadow-sm transition hover:shadow-md active:cursor-grabbing">
                    <div class="mb-1 flex items-center gap-1.5 text-[11px] text-slate-400">
                        <TypeIcon :types="types" :type-id="t.type_id" />
                        <span class="num" dir="ltr">{{ t.code }}</span>
                        <span v-if="t.list && !listId" class="truncate">· {{ t.list.name }}</span>
                        <span v-if="groupBy !== 'status'" class="ms-auto rounded px-1.5 py-0.5 text-[10px] font-semibold text-white" :style="{ background: t.status?.color }">{{ t.status?.name }}</span>
                    </div>
                    <Link :href="`/tasks/${t.id}`" class="block text-sm font-medium leading-snug hover:text-brand" :class="t.completed ? 'text-slate-400 line-through' : 'text-slate-800'">{{ t.title }}</Link>
                    <div v-if="t.tags.length" class="mt-2 flex flex-wrap gap-1">
                        <span v-for="g in t.tags" :key="g.id" class="rounded px-1.5 py-0.5 text-[10px] font-medium" :style="{ background: g.color + '22', color: g.color }">{{ g.name }}</span>
                    </div>
                    <div class="no-drag mt-2 flex items-center gap-2">
                        <PeoplePicker :model-value="t.assignees.map((u) => u.id)" :users="users" :editable="can?.assign" @update:model-value="(v) => patchTask(t.id, { assignees: v })" />
                        <DueInput :model-value="t.due" :overdue="t.overdue" :editable="can?.edit" placeholder="" @update:model-value="(v) => patchTask(t.id, dateParams('due', v))" />
                        <span class="ms-auto flex items-center gap-2 text-[11px] text-slate-400">
                            <span v-if="t.children_count" class="flex items-center gap-0.5"><GitBranch class="size-3" /><span class="num">{{ t.children_done }}/{{ t.children_count }}</span></span>
                            <span v-if="t.comments_count" class="flex items-center gap-0.5"><MessageSquare class="size-3" /><span class="num">{{ t.comments_count }}</span></span>
                            <PriorityPicker :model-value="t.priority" :priorities="priorities" :editable="can?.edit" @update:model-value="(v) => patchTask(t.id, { priority: v })" />
                        </span>
                    </div>
                </div>
            </VueDraggable>
        </div>
    </div>
</template>

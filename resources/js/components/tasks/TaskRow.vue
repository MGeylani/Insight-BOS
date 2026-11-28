<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronDown, GitBranch, CircleCheck, Circle, Plus, MessageSquare, Hourglass, Repeat } from '@lucide/vue';
import StatusPill from './StatusPill.vue';
import PriorityPicker from './PriorityPicker.vue';
import PeoplePicker from './PeoplePicker.vue';
import DueInput from './DueInput.vue';
import TagPicker from './TagPicker.vue';
import TypeIcon from './TypeIcon.vue';
import { patchTask, dateParams, minutesLabel } from '@/lib/tasks';

defineOptions({ name: 'TaskRow' });
const props = defineProps({
    task: Object, statuses: Array, users: Array, tags: Array, priorities: Object, can: Object,
    types: { type: Array, default: () => [] },
    level: { type: Number, default: 0 }, showLocation: Boolean, selected: { type: Array, default: () => [] }, selectable: Boolean,
});
const emit = defineEmits(['add-subtask', 'toggle-select']);
const open = ref(false);
const editable = computed(() => props.can?.edit);
const rowStatuses = computed(() => props.statuses);
const doneStatus = computed(() => props.statuses.find((s) => s.group === 'done') ?? props.statuses.find((s) => s.group === 'closed'));
const firstStatus = computed(() => props.statuses.find((s) => s.group === 'not_started') ?? props.statuses[0]);

const set = (data) => patchTask(props.task.id, data);
const toggleDone = () => {
    const target = props.task.completed ? firstStatus.value : doneStatus.value;
    if (target) set({ status_id: target.id });
};
</script>

<template>
    <div class="group flex items-center gap-2 border-b border-slate-100 px-3 py-1.5 text-sm hover:bg-slate-50/80" :class="{ 'bg-brand/5': selected.includes(task.id) }">
        <input v-if="selectable" type="checkbox" class="size-3.5 shrink-0 rounded border-slate-300" :checked="selected.includes(task.id)" @change="emit('toggle-select', task.id)" />
        <div class="flex min-w-0 flex-1 items-center gap-1.5" :style="{ paddingInlineStart: level * 22 + 'px' }">
            <button v-if="task.children?.length || task.children_count" type="button" class="rounded p-0.5 text-slate-400 hover:bg-slate-200" @click="open = !open">
                <ChevronDown v-if="open" class="size-3.5" /><ChevronLeft v-else class="size-3.5" />
            </button>
            <span v-else class="w-[18px]" />
            <button type="button" class="shrink-0 disabled:cursor-default" :disabled="!editable" :title="task.completed ? 'إعادة فتح' : 'إنهاء'" @click="toggleDone">
                <CircleCheck v-if="task.completed" class="size-4 text-emerald-600" />
                <Circle v-else class="size-4 text-slate-300 hover:text-emerald-600" />
            </button>
            <TypeIcon :types="types" :type-id="task.type_id" />
            <Link :href="`/tasks/${task.id}`" class="min-w-0 truncate font-medium hover:text-brand" :class="task.completed ? 'text-slate-400 line-through' : 'text-slate-800'">{{ task.title }}</Link>
            <span class="num shrink-0 text-[11px] text-slate-400" dir="ltr">{{ task.code }}</span>
            <Hourglass v-if="task.blocked && !task.completed" class="size-3.5 shrink-0 text-amber-600" title="بانتظار مهام لم تُغلق" />
            <Repeat v-if="task.recurrence" class="size-3.5 shrink-0 text-plum" :title="task.recurrence_label" />
            <span v-if="task.children_count" class="flex shrink-0 items-center gap-0.5 text-[11px] text-slate-400" title="مهام فرعية"><GitBranch class="size-3" /><span class="num">{{ task.children_done }}/{{ task.children_count }}</span></span>
            <span v-if="task.comments_count" class="flex shrink-0 items-center gap-0.5 text-[11px] text-slate-400" title="تعليقات"><MessageSquare class="size-3" /><span class="num">{{ task.comments_count }}</span></span>
            <span v-if="showLocation && task.list" class="hidden shrink-0 truncate text-[11px] text-slate-400 lg:inline">· {{ task.list.name }}</span>
            <TagPicker class="hidden md:flex" :model-value="task.tags.map((t) => t.id)" :tags="tags" :editable="editable" :can-create="editable" @update:model-value="(v) => set({ tags: v })" />
            <button v-if="can?.create && level < 2" type="button" class="hidden shrink-0 rounded p-0.5 text-slate-400 hover:bg-slate-200 hover:text-brand group-hover:inline-flex" title="مهمة فرعية" @click="open = true; emit('add-subtask', task)"><Plus class="size-3.5" /></button>
        </div>
        <div class="w-28 shrink-0"><StatusPill :status="task.status" :statuses="rowStatuses" :editable="editable" @change="(s) => set({ status_id: s.id })" /></div>
        <div class="w-20 shrink-0"><PeoplePicker :model-value="task.assignees.map((u) => u.id)" :users="users" :editable="can?.assign" @update:model-value="(v) => set({ assignees: v })" /></div>
        <div class="w-24 shrink-0"><DueInput :model-value="task.due" :overdue="task.overdue" :editable="editable" placeholder="" @update:model-value="(v) => set(dateParams('due', v))" /></div>
        <div class="w-8 shrink-0"><PriorityPicker :model-value="task.priority" :priorities="priorities" :editable="editable" @update:model-value="(v) => set({ priority: v })" /></div>
        <div class="num hidden w-14 shrink-0 text-xs text-slate-500 sm:block">{{ minutesLabel(task.estimate) }}</div>
    </div>
    <template v-if="open">
        <TaskRow v-for="c in task.children" :key="c.id" :task="c" :statuses="statuses" :users="users" :tags="tags" :priorities="priorities" :can="can" :types="types"
            :level="level + 1" :show-location="false" :selected="selected" :selectable="selectable" @add-subtask="(t) => emit('add-subtask', t)" @toggle-select="(id) => emit('toggle-select', id)">
            <template #after="s"><slot name="after" v-bind="s" /></template>
        </TaskRow>
        <slot name="after" :task="task" />
    </template>
</template>

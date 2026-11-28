<script setup>
import { Link, router } from '@inertiajs/vue3';
import { Hourglass, Ban, Link2, X, Plus, CircleCheck } from '@lucide/vue';
import Dropdown from '@/components/Dropdown.vue';
import TaskPicker from './TaskPicker.vue';
import { dueLabel, keep } from '@/lib/tasks';

const props = defineProps({ taskId: Number, deps: Object, editable: Boolean });
const groups = [
    { key: 'waiting_on', type: 'waiting', label: 'بانتظار', hint: 'لا تُغلق هذه قبلها', icon: Hourglass, tone: 'text-amber-600' },
    { key: 'blocking', type: 'blocking', label: 'تعطّل', hint: 'تنتظر هذه المهمة', icon: Ban, tone: 'text-red-600' },
    { key: 'links', type: 'link', label: 'مرتبطة', hint: '', icon: Link2, tone: 'text-brand' },
];
const add = (type, other, close) => { router.post(`/tasks/${props.taskId}/dependencies`, { other_id: other.id, type }, keep); close(); };
const remove = (d) => router.delete(`/tasks/dependencies/${d.dep_id}`, keep);
</script>

<template>
    <div class="card p-5 text-sm">
        <h3 class="mb-3 font-semibold text-navy">الاعتماديات والروابط</h3>
        <div v-for="g in groups" :key="g.key" class="mb-3 last:mb-0">
            <div class="mb-1 flex items-center gap-1.5 text-xs font-semibold text-slate-600">
                <component :is="g.icon" class="size-3.5" :class="g.tone" /> {{ g.label }}
                <span v-if="g.hint" class="font-normal text-slate-400">· {{ g.hint }}</span>
                <Dropdown v-if="editable" width="w-80" class="ms-auto">
                    <template #trigger><button type="button" class="rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-brand" :title="`إضافة: ${g.label}`"><Plus class="size-3.5" /></button></template>
                    <template #default="{ close }"><TaskPicker :exclude="taskId" @pick="(o) => add(g.type, o, close)" /></template>
                </Dropdown>
            </div>
            <p v-for="d in deps[g.key]" :key="d.dep_id" class="group flex items-center gap-2 py-0.5 text-xs">
                <CircleCheck v-if="d.completed" class="size-3.5 shrink-0 text-emerald-600" />
                <span class="num shrink-0 text-slate-400" dir="ltr">{{ d.code }}</span>
                <Link :href="`/tasks/${d.id}`" class="truncate hover:text-brand" :class="d.completed ? 'text-slate-400 line-through' : 'text-slate-700'">{{ d.title }}</Link>
                <span v-if="d.due" class="shrink-0 text-slate-400">{{ dueLabel(d.due) }}</span>
                <button v-if="editable" type="button" class="ms-auto rounded p-0.5 text-slate-300 opacity-0 hover:text-red-600 group-hover:opacity-100" @click="remove(d)"><X class="size-3.5" /></button>
            </p>
            <p v-if="!deps[g.key].length" class="text-xs text-slate-400">—</p>
        </div>
    </div>
</template>

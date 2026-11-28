<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Eye, EyeOff, Trash2, FolderInput, ListPlus, X, GitBranch, CalendarDays, Clock, Flag, Users, Tag as TagIcon, Milestone, CircleDot, History, Plus,
} from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Btn from '@/components/Btn.vue';
import Dropdown from '@/components/Dropdown.vue';
import StatusPill from '@/components/tasks/StatusPill.vue';
import PriorityPicker from '@/components/tasks/PriorityPicker.vue';
import PeoplePicker from '@/components/tasks/PeoplePicker.vue';
import DueInput from '@/components/tasks/DueInput.vue';
import TagPicker from '@/components/tasks/TagPicker.vue';
import TaskRow from '@/components/tasks/TaskRow.vue';
import QuickAdd from '@/components/tasks/QuickAdd.vue';
import ChecklistPanel from '@/components/tasks/ChecklistPanel.vue';
import { formatDateTime } from '@/lib/format';
import { patchTask, dateParams, minutesLabel, parseMinutes, docToPlain, keep } from '@/lib/tasks';

const props = defineProps({
    task: Object, activity: Array, statuses: Array, milestones: Array, moveLists: Array, linkLists: Array,
    priorities: Object, groups: Object, users: Array, tags: Array, can: Object,
});
const page = usePage();
const t = computed(() => props.task);
const errors = computed(() => Object.values(page.props.errors ?? {}));
const set = (data) => patchTask(t.value.id, data);

const title = ref('');
const description = ref('');
const estimate = ref('');
watch(t, (v) => {
    title.value = v.title;
    description.value = docToPlain(v.description);
    estimate.value = minutesLabel(v.estimate);
}, { immediate: true });
const saveTitle = () => title.value.trim() && title.value !== t.value.title && set({ title: title.value });
const saveDescription = () => description.value !== docToPlain(t.value.description) && set({ description: description.value });
const saveEstimate = () => {
    const m = parseMinutes(estimate.value);
    if (m !== t.value.estimate) set({ time_estimate_min: m });
    estimate.value = minutesLabel(m);
};

const addingSub = ref(false);
const linkQ = ref('');
const linkable = computed(() => props.linkLists.filter((l) => l.id !== t.value.list_id && !t.value.other_lists.some((o) => o.id === l.id)
    && (!linkQ.value || `${l.client} ${l.project ?? ''} ${l.name}`.includes(linkQ.value))).slice(0, 40));

const watch_ = () => router.post(`/tasks/${t.value.id}/watch`, {}, keep);
const move = (l) => router.post(`/tasks/${t.value.id}/move`, { task_list_id: l.id }, keep);
const link = (l) => router.post(`/tasks/${t.value.id}/lists`, { task_list_id: l.id }, keep);
const unlink = (l) => router.delete(`/tasks/${t.value.id}/lists/${l.id}`, keep);
const archive = () => confirm(`أرشفة المهمة ${t.value.code}${t.value.children.length ? ' ومهامها الفرعية' : ''}؟`) && router.delete(`/tasks/${t.value.id}`);
const childCan = computed(() => ({ ...props.can, create: props.can.create && t.value.depth < 2 }));
</script>

<template>
    <AppLayout :title="`${t.code} · ${t.title}`">
        <nav class="mb-3 flex flex-wrap items-center gap-1 text-xs text-slate-500">
            <Link :href="`/tasks?client=${t.home.client?.id}`" class="hover:underline">{{ t.home.client?.name }}</Link>
            <template v-if="t.home.project"> ← <Link :href="`/tasks?project=${t.home.project.id}`" class="hover:underline">{{ t.home.project.name }}</Link></template>
            ← <Link :href="`/tasks?list=${t.home.id}`" class="hover:underline">{{ t.home.name }}</Link>
            <template v-for="p in t.parents" :key="p.id"> ← <Link :href="`/tasks/${p.id}`" class="hover:underline">{{ p.code }} {{ p.title }}</Link></template>
        </nav>

        <div class="mb-6 flex flex-wrap items-start gap-3">
            <span class="num mt-2 rounded-md bg-slate-100 px-2 py-0.5 text-sm font-semibold text-slate-500" dir="ltr">{{ t.code }}</span>
            <textarea v-model="title" rows="1" class="min-w-0 flex-1 resize-none rounded-xl border border-transparent bg-transparent px-2 py-1 text-2xl font-bold text-navy outline-none [field-sizing:content] hover:border-slate-200 focus:border-brand"
                :readonly="!can.edit" @blur="saveTitle" @keydown.enter.prevent="$event.target.blur()" />
            <div class="flex items-center gap-2">
                <Btn variant="secondary" size="sm" :title="t.watching ? 'إلغاء المتابعة' : 'متابعة'" @click="watch_">
                    <EyeOff v-if="t.watching" class="size-4" /><Eye v-else class="size-4" /> {{ t.watching ? 'تتابعها' : 'متابعة' }}
                </Btn>
                <Dropdown v-if="can.edit && !t.parent_id" width="w-64">
                    <template #trigger><Btn variant="secondary" size="sm" title="نقل إلى قائمة أخرى"><FolderInput class="size-4" /></Btn></template>
                    <template #default="{ close }">
                        <p class="px-2 pb-1 pt-1 text-[11px] text-slate-400">نقل إلى قائمة (العميل نفسه)</p>
                        <button v-for="l in moveLists.filter((x) => x.id !== t.list_id)" :key="l.id" type="button" class="block w-full truncate rounded-lg px-2 py-1.5 text-start text-sm hover:bg-slate-50" @click="move(l); close()">
                            {{ l.name }}<span v-if="l.project" class="text-xs text-slate-400"> · {{ l.project }}</span>
                        </button>
                    </template>
                </Dropdown>
                <Dropdown v-if="can.edit" width="w-72" @close="linkQ = ''">
                    <template #trigger><Btn variant="secondary" size="sm" title="إظهارها في قائمة أخرى أيضاً"><ListPlus class="size-4" /></Btn></template>
                    <template #default="{ close }">
                        <input v-model="linkQ" class="field-input mb-1 py-1.5 text-sm" placeholder="ابحث عن قائمة…" />
                        <button v-for="l in linkable" :key="l.id" type="button" class="block w-full truncate rounded-lg px-2 py-1.5 text-start text-sm hover:bg-slate-50" @click="link(l); close()">
                            {{ l.name }} <span class="text-xs text-slate-400">· {{ l.client }}<template v-if="l.project"> ← {{ l.project }}</template></span>
                        </button>
                    </template>
                </Dropdown>
                <Btn v-if="can.delete" variant="ghost" size="sm" title="أرشفة" @click="archive"><Trash2 class="size-4" /></Btn>
            </div>
        </div>

        <div v-if="errors.length" class="mb-4 rounded-2xl bg-red-50 p-4 text-sm text-red-800"><p v-for="e in errors" :key="e">{{ e }}</p></div>

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <div class="card p-5">
                    <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                        <div class="flex items-center gap-3"><dt class="flex w-24 shrink-0 items-center gap-1.5 text-slate-500"><CircleDot class="size-4" /> الحالة</dt>
                            <dd><StatusPill :status="t.status" :statuses="statuses" :editable="can.edit" @change="(s) => set({ status_id: s.id })" /></dd></div>
                        <div class="flex items-center gap-3"><dt class="flex w-24 shrink-0 items-center gap-1.5 text-slate-500"><Users class="size-4" /> المسؤولون</dt>
                            <dd class="flex items-center gap-2"><PeoplePicker :model-value="t.assignees.map((u) => u.id)" :users="users" :editable="can.assign" :max="5" placeholder="إسناد" @update:model-value="(v) => set({ assignees: v })" />
                                <span class="text-xs text-slate-500">{{ t.assignees.map((u) => u.name).join('، ') }}</span></dd></div>
                        <div class="flex items-center gap-3"><dt class="flex w-24 shrink-0 items-center gap-1.5 text-slate-500"><CalendarDays class="size-4" /> البدء</dt>
                            <dd><DueInput :model-value="t.start" :editable="can.edit" placeholder="بلا تاريخ" @update:model-value="(v) => set(dateParams('start', v))" /></dd></div>
                        <div class="flex items-center gap-3"><dt class="flex w-24 shrink-0 items-center gap-1.5 text-slate-500"><CalendarDays class="size-4" /> التسليم</dt>
                            <dd><DueInput :model-value="t.due" :overdue="t.overdue" :editable="can.edit" placeholder="بلا تاريخ" @update:model-value="(v) => set(dateParams('due', v))" /></dd></div>
                        <div class="flex items-center gap-3"><dt class="flex w-24 shrink-0 items-center gap-1.5 text-slate-500"><Flag class="size-4" /> الأولوية</dt>
                            <dd><PriorityPicker :model-value="t.priority" :priorities="priorities" :editable="can.edit" label @update:model-value="(v) => set({ priority: v })" /></dd></div>
                        <div class="flex items-center gap-3"><dt class="flex w-24 shrink-0 items-center gap-1.5 text-slate-500"><Clock class="size-4" /> التقدير</dt>
                            <dd><input v-model="estimate" class="w-28 rounded-md border border-transparent px-1 py-0.5 text-sm hover:border-slate-200 focus:border-brand focus:outline-none" placeholder="مثل 2س 30د" :readonly="!can.edit" @blur="saveEstimate" @keydown.enter="$event.target.blur()" /></dd></div>
                        <div class="flex items-center gap-3"><dt class="flex w-24 shrink-0 items-center gap-1.5 text-slate-500"><TagIcon class="size-4" /> الوسوم</dt>
                            <dd><TagPicker :model-value="t.tags.map((x) => x.id)" :tags="tags" :editable="can.edit" :can-create="can.edit" @update:model-value="(v) => set({ tags: v })" /></dd></div>
                        <div v-if="milestones.length" class="flex items-center gap-3"><dt class="flex w-24 shrink-0 items-center gap-1.5 text-slate-500"><Milestone class="size-4" /> المرحلة</dt>
                            <dd><select :value="t.milestone_id ?? ''" class="rounded-md border-0 bg-transparent py-0.5 text-sm hover:bg-slate-50" :disabled="!can.edit" @change="set({ project_milestone_id: $event.target.value || null })">
                                <option value="">—</option><option v-for="m in milestones" :key="m.id" :value="m.id">{{ m.title }}</option></select></dd></div>
                    </dl>
                </div>

                <div class="card p-5">
                    <h3 class="mb-2 font-semibold text-navy">الوصف</h3>
                    <textarea v-model="description" rows="6" class="field-input leading-relaxed" placeholder="تفاصيل المهمة، الموجز، الروابط…" :readonly="!can.edit" @blur="saveDescription" />
                </div>

                <div class="card overflow-hidden">
                    <div class="flex items-center gap-2 border-b border-slate-100 px-5 py-3">
                        <GitBranch class="size-4 text-plum" /><h3 class="me-auto font-semibold text-navy">المهام الفرعية</h3>
                        <span v-if="t.progress.total" class="num text-xs text-slate-500">{{ t.progress.percent }}%</span>
                        <Btn v-if="childCan.create" size="sm" variant="secondary" @click="addingSub = true"><Plus class="size-4" /> مهمة فرعية</Btn>
                    </div>
                    <TaskRow v-for="c in t.children" :key="c.id" :task="c" :statuses="statuses" :users="users" :tags="tags" :priorities="priorities" :can="{ ...can, create: false }" />
                    <QuickAdd v-if="addingSub" :parent-id="t.id" autofocus placeholder="مهمة فرعية… (Enter للحفظ)" @done="addingSub = false" />
                    <p v-if="!t.children.length && !addingSub" class="px-5 py-4 text-sm text-slate-500">لا توجد مهام فرعية.</p>
                </div>

                <ChecklistPanel :task-id="t.id" :checklists="t.checklists" :users="users" :editable="can.edit" />
            </div>

            <div class="space-y-6">
                <div class="card p-5 text-sm">
                    <h3 class="mb-3 font-semibold text-navy">القوائم</h3>
                    <p class="mb-2 flex items-center gap-2"><span class="rounded bg-navy/5 px-1.5 text-[10px] text-navy">الأساسية</span><Link :href="`/tasks?list=${t.home.id}`" class="text-brand hover:underline">{{ t.home.name }}</Link></p>
                    <p v-for="l in t.other_lists" :key="l.id" class="mb-1 flex items-center gap-2">
                        <Link :href="`/tasks?list=${l.id}`" class="truncate text-brand hover:underline">{{ l.name }}</Link><span class="truncate text-xs text-slate-400">{{ l.client }}</span>
                        <button v-if="can.edit" type="button" class="ms-auto rounded p-0.5 text-slate-400 hover:text-red-600" title="إزالة من هذه القائمة" @click="unlink(l)"><X class="size-3.5" /></button>
                    </p>
                    <div class="mt-4 border-t border-slate-100 pt-3">
                        <p class="mb-1 text-xs text-slate-500">المتابعون</p>
                        <PeoplePicker :model-value="t.watchers.map((u) => u.id)" :users="users" :editable="can.edit" :max="8" placeholder="إضافة" @update:model-value="(v) => set({ watchers: v })" />
                    </div>
                    <p class="mt-4 text-xs text-slate-500">أنشأها {{ t.creator?.name ?? '—' }} · {{ formatDateTime(t.created_at) }}</p>
                    <p v-if="t.completed_at" class="text-xs text-emerald-700">اكتملت {{ formatDateTime(t.completed_at) }}</p>
                </div>

                <div class="card p-5">
                    <h3 class="mb-3 flex items-center gap-2 font-semibold text-navy"><History class="size-4 text-slate-400" /> النشاط</h3>
                    <ol class="relative space-y-3 border-s border-slate-200 ps-4">
                        <li v-for="a in activity" :key="a.id" class="text-sm">
                            <span class="absolute -start-1.5 mt-1.5 size-3 rounded-full border-2 border-white bg-brand" />
                            <p class="text-slate-700">{{ a.description }}</p>
                            <p class="text-xs text-slate-500">{{ a.user ?? '—' }} · {{ formatDateTime(a.created_at) }}</p>
                        </li>
                    </ol>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

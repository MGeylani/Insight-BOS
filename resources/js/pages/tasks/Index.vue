<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Plus, Pencil, Trash2, Palette, ListPlus, Settings2 } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Btn from '@/components/Btn.vue';
import Dropdown from '@/components/Dropdown.vue';
import TaskTree from '@/components/tasks/TaskTree.vue';
import TaskListView from '@/components/tasks/TaskListView.vue';
import TaskFilters from '@/components/tasks/TaskFilters.vue';
import StatusEditor from '@/components/tasks/StatusEditor.vue';
import ListFormModal from '@/components/tasks/ListFormModal.vue';
import NewTaskModal from '@/components/tasks/NewTaskModal.vue';
import { useAuth } from '@/lib/auth';

const props = defineProps({
    tree: Array, location: Object, tasks: Array, statuses: Array, filters: Object, priorities: Object, groups: Object,
    users: Array, tags: Array, can: Object, schemeTemplates: Array, milestones: { type: Array, default: () => [] },
});
const page = usePage();
const { can: canDo } = useAuth();
const errors = computed(() => Object.values(page.props.errors ?? {}));
const loc = computed(() => props.location);
const locQuery = computed(() => (loc.value ? { [loc.value.type]: loc.value.id } : {}));
const title = computed(() => loc.value?.name ?? 'كل المهام');

const statusEditor = ref(false);
const listForm = ref(false);
const editList = ref(null);
const newTask = ref(false);

const applyFilters = (f) => router.get('/tasks', { ...locQuery.value, ...f }, { preserveState: true, preserveScroll: true, replace: true });
const openNewList = () => { editList.value = null; listForm.value = true; };
const openEditList = () => { editList.value = loc.value; listForm.value = true; };
const deleteList = () => confirm(`حذف القائمة «${loc.value.name}»؟ (تُحذف فقط إن كانت فارغة)`) && router.delete(`/tasks/lists/${loc.value.id}`);

const newListClient = computed(() => (loc.value?.type === 'client' ? loc.value.id : loc.value?.client?.id ?? null));
const newListProject = computed(() => (loc.value?.type === 'project' ? loc.value.id : null));
const done = computed(() => props.tasks.filter((t) => t.completed && !t.parent_id).length);
const top = computed(() => props.tasks.filter((t) => !t.parent_id).length);
</script>

<template>
    <AppLayout :title="title">
        <div class="grid gap-6 lg:grid-cols-[17rem_minmax(0,1fr)]">
            <aside class="lg:sticky lg:top-24 lg:max-h-[calc(100vh-7rem)] lg:overflow-y-auto">
                <TaskTree :tree="tree" :location="location" />
            </aside>

            <section class="min-w-0">
                <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs text-slate-500">
                            <template v-if="loc?.client && loc.type !== 'client'"><Link :href="`/tasks?client=${loc.client.id}`" class="hover:underline">{{ loc.client.name }}</Link></template>
                            <template v-if="loc?.project && loc.type === 'list'"> ← <Link :href="`/tasks?project=${loc.project.id}`" class="hover:underline">{{ loc.project.name }}</Link></template>
                            <template v-if="!loc">كل ما يمكنك رؤيته</template>
                        </p>
                        <h1 class="flex items-center gap-2 truncate text-2xl font-bold text-navy">
                            {{ title }}
                            <span v-if="loc?.type === 'client' && loc.prefix" class="num rounded-md bg-slate-100 px-2 py-0.5 text-sm font-semibold text-slate-500" dir="ltr">{{ loc.prefix }}</span>
                        </h1>
                        <p v-if="loc?.description" class="mt-1 text-sm text-slate-500">{{ loc.description }}</p>
                        <p class="mt-1 text-xs text-slate-500"><span class="num">{{ top }}</span> مهمة<template v-if="filters.closed"> · <span class="num">{{ done }}</span> مكتملة</template></p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Link v-if="loc?.type === 'project'" :href="`/projects/${loc.id}`" class="text-sm text-brand hover:underline">مساحة المشروع</Link>
                        <Btn v-if="can.new_list" variant="secondary" @click="openNewList"><ListPlus class="size-4" /> قائمة</Btn>
                        <Dropdown v-if="loc?.type === 'list' && can.manage_list" width="w-48">
                            <template #trigger><Btn variant="secondary" title="إعدادات القائمة"><Settings2 class="size-4" /></Btn></template>
                            <template #default="{ close }">
                                <button type="button" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50" @click="openEditList(); close()"><Pencil class="size-4" /> تعديل القائمة</button>
                                <button type="button" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50" @click="statusEditor = true; close()"><Palette class="size-4" /> الحالات</button>
                                <button type="button" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-red-600 hover:bg-red-50" @click="deleteList(); close()"><Trash2 class="size-4" /> حذف القائمة</button>
                            </template>
                        </Dropdown>
                        <Btn v-if="can.create" @click="newTask = true"><Plus class="size-4" /> مهمة</Btn>
                    </div>
                </div>

                <div v-if="errors.length" class="mb-4 rounded-2xl bg-red-50 p-4 text-sm text-red-800"><p v-for="e in errors" :key="e">{{ e }}</p></div>

                <TaskFilters :filters="filters" :users="users" :priorities="priorities" :tags="tags" :statuses="statuses" @change="applyFilters" />

                <TaskListView :tasks="tasks" :statuses="statuses" :users="users" :tags="tags" :priorities="priorities" :can="can"
                    :list-id="loc?.type === 'list' ? loc.id : null" :show-location="loc?.type !== 'list'" />
                <div v-if="!tasks.length" class="card mt-4 p-10 text-center text-sm text-slate-500">
                    لا توجد مهام هنا<template v-if="Object.keys(filters).length"> تطابق الفلاتر</template>.
                </div>
            </section>
        </div>

        <StatusEditor v-if="loc?.type === 'list'" v-model="statusEditor" :statuses="statuses" :groups="groups" :url="`/tasks/lists/${loc.id}/statuses`" />
        <ListFormModal v-model="listForm" :list="editList" :client-id="newListClient" :project-id="newListProject" :milestones="milestones" :templates="schemeTemplates" />
        <NewTaskModal v-model="newTask" :tree="tree" :location="location" />
    </AppLayout>
</template>

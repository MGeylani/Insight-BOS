<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Plus, Pencil, Trash2, Palette, ListPlus, Settings2, Shapes, List, Columns3, CalendarDays, Table2, Bookmark, BookmarkPlus, Download, Users2, Lock, SlidersHorizontal, Check, ChartGantt, ChevronDown, Pin, PinOff, LayoutTemplate, Zap, FileInput, Share2,
} from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Btn from '@/components/Btn.vue';
import Dropdown from '@/components/Dropdown.vue';
import TaskTree from '@/components/tasks/TaskTree.vue';
import TaskListView from '@/components/tasks/TaskListView.vue';
import TaskBoardView from '@/components/tasks/TaskBoardView.vue';
import TaskCalendarView from '@/components/tasks/TaskCalendarView.vue';
import TaskTableView from '@/components/tasks/TaskTableView.vue';
import TaskFilters from '@/components/tasks/TaskFilters.vue';
import BulkBar from '@/components/tasks/BulkBar.vue';
import StatusEditor from '@/components/tasks/StatusEditor.vue';
import ListFormModal from '@/components/tasks/ListFormModal.vue';
import NewTaskModal from '@/components/tasks/NewTaskModal.vue';
import SaveViewModal from '@/components/tasks/SaveViewModal.vue';
import FieldsManager from '@/components/tasks/FieldsManager.vue';
import TaskGanttView from '@/components/tasks/TaskGanttView.vue';
import TemplatesModal from '@/components/tasks/TemplatesModal.vue';
import ShareModal from '@/components/tasks/ShareModal.vue';
import { groupLabels, sortLabels, columnLabels } from '@/lib/tasks';

const props = defineProps({
    tree: Array, location: Object, tasks: Array, statuses: Array, filters: Object, priorities: Object, groups: Object,
    users: Array, tags: Array, can: Object, schemeTemplates: Array, milestones: { type: Array, default: () => [] },
    view: Object, savedView: Object, savedViews: Array, columns: Array, lists: Array,
    types: { type: Array, default: () => [] }, customFields: { type: Array, default: () => [] }, canManageFields: Boolean, templates: { type: Array, default: () => [] },
});
const page = usePage();
const errors = computed(() => Object.values(page.props.errors ?? {}));
const loc = computed(() => props.location);
const locQuery = computed(() => (loc.value ? { [loc.value.type]: loc.value.id } : {}));
const title = computed(() => loc.value?.name ?? 'كل المهام');

const viewTypes = [
    { key: 'list', label: 'قائمة', icon: List },
    { key: 'board', label: 'لوحة', icon: Columns3 },
    { key: 'calendar', label: 'تقويم', icon: CalendarDays },
    { key: 'table', label: 'جدول', icon: Table2 },
    { key: 'gantt', label: 'Gantt', icon: ChartGantt },
];
const v = computed(() => props.view);

/** Navigate with the current location, filters and view settings, changing some of them. */
function go(changes = {}, { keepSaved = true } = {}) {
    const next = { ...v.value, ...changes.view };
    const params = {
        ...locQuery.value,
        ...(changes.filters ?? props.filters),
        view: next.view,
        group: next.group, sort: next.sort, dir: next.dir, columns: next.columns, date: next.date,
        subtasks: next.subtasks ? undefined : 0,
    };
    if (keepSaved && props.savedView) params.saved = props.savedView.id;
    router.get('/tasks', params, { preserveState: true, preserveScroll: true, replace: true });
}
const setView = (k) => go({ view: { view: k, group: k === 'table' ? 'none' : (v.value.group === 'none' ? 'status' : v.value.group) } }, { keepSaved: false });
const openSaved = (sv) => router.get('/tasks', { ...(sv.location_type ? locQuery.value : locQuery.value), saved: sv.id });
const toggleColumn = (c) => go({ view: { columns: v.value.columns.includes(c) ? v.value.columns.filter((x) => x !== c) : [...v.value.columns, c] } });
const exportUrl = computed(() => {
    const p = new URLSearchParams();
    Object.entries({ ...locQuery.value, sort: v.value.sort, dir: v.value.dir }).forEach(([k, x]) => p.append(k, x));
    Object.entries(props.filters).forEach(([k, x]) => (Array.isArray(x) ? x.forEach((y) => p.append(`${k}[]`, y)) : p.append(k, x === true ? 1 : x)));
    return `/tasks/export?${p}`;
});
const updateSaved = () => router.put(`/tasks/views/${props.savedView.id}`, { replace_config: true, type: v.value.view, config: { ...v.value, filters: props.filters } }, { preserveScroll: true });
const toggleShare = () => router.put(`/tasks/views/${props.savedView.id}`, { is_shared: !props.savedView.is_shared }, { preserveScroll: true, preserveState: true });
const deleteSaved = () => confirm(`حذف العرض «${props.savedView.name}»؟`) && router.delete(`/tasks/views/${props.savedView.id}`);

// Selection for bulk actions (list and table).
const selected = ref([]);
watch(() => props.tasks, (rows) => { const ids = new Set(rows.map((t) => t.id)); selected.value = selected.value.filter((id) => ids.has(id)); });
const toggleSelect = (id) => { selected.value = selected.value.includes(id) ? selected.value.filter((x) => x !== id) : [...selected.value, id]; };
const selectMany = (ids, on) => { selected.value = on ? [...new Set([...selected.value, ...ids])] : selected.value.filter((x) => !ids.includes(x)); };
const bulkCan = computed(() => ({ edit: props.can.edit, assign: props.can.assign, delete: props.can.delete }));

const statusEditor = ref(false);
const listForm = ref(false);
const editList = ref(null);
const newTask = ref(false);
const saveView = ref(false);
const fieldsManager = ref(false);
const allColumns = computed(() => [...props.columns, ...props.customFields.map((f) => `cf:${f.id}`)]);
const colLabel = (c) => (c.startsWith('cf:') ? props.customFields.find((f) => `cf:${f.id}` === c)?.name : columnLabels[c]);
const dropdownFields = computed(() => props.customFields.filter((f) => f.type === 'dropdown'));
const openNewList = () => { editList.value = null; listForm.value = true; };
const openEditList = () => { editList.value = loc.value; listForm.value = true; };
const deleteList = () => confirm(`حذف القائمة «${loc.value.name}»؟ (تُحذف فقط إن كانت فارغة)`) && router.delete(`/tasks/lists/${loc.value.id}`);
const newListClient = computed(() => (loc.value?.type === 'client' ? loc.value.id : loc.value?.client?.id ?? null));
const newListProject = computed(() => (loc.value?.type === 'project' ? loc.value.id : null));
const top = computed(() => props.tasks.filter((t) => !t.parent_id).length);
const listId = computed(() => (loc.value?.type === 'list' ? loc.value.id : null));
const shareOpen = ref(false);
const treeKey = ref(0);
const tplOpen = ref(false);
const tplMode = ref('apply');
const tplSource = computed(() => (loc.value && loc.value.type !== 'client' ? { from: loc.value.type, id: loc.value.id, name: loc.value.name } : null));
const tplTarget = computed(() => (loc.value?.type === 'list' ? { list_id: loc.value.id } : loc.value?.type === 'project' ? { project_id: loc.value.id } : loc.value?.type === 'client' ? { client_id: loc.value.id } : {}));
const tplKinds = computed(() => (loc.value?.type === 'list' ? ['task'] : loc.value?.type === 'project' ? ['list', 'project'] : ['list']));
const openTpl = (mode) => { tplMode.value = mode; tplOpen.value = true; };
const automationsUrl = computed(() => `/tasks/automations${loc.value ? `?${loc.value.type}=${loc.value.id}` : ''}`);
const ganttExportUrl = computed(() => exportUrl.value.replace('/tasks/export?', '/tasks/export/gantt?') + (v.value.subtasks ? '' : '&subtasks=0'));

// Location picker: a dropdown by the title by default; can be pinned as a side panel (remembered by the browser).
const readPin = () => { try { return localStorage.getItem('bos.tasks.tree') === 'pinned'; } catch { return false; } };
const pinned = ref(readPin());
const setPinned = (on) => { pinned.value = on; try { localStorage.setItem('bos.tasks.tree', on ? 'pinned' : 'menu'); } catch { /* private mode */ } };
const menuItem = 'flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50';
</script>

<template>
    <AppLayout :title="title" wide>
        <div class="grid gap-6" :class="pinned ? 'lg:grid-cols-[17rem_minmax(0,1fr)]' : ''">
            <aside v-if="pinned" class="lg:sticky lg:top-24 lg:max-h-[calc(100vh-7rem)] lg:overflow-y-auto">
                <div class="card">
                    <div class="flex items-center justify-between border-b border-slate-100 px-3 py-2 text-xs text-slate-500">
                        العملاء والمشاريع والقوائم
                        <button type="button" class="rounded p-1 hover:bg-slate-100 hover:text-navy" title="إلغاء التثبيت (يصبح قائمة منسدلة بجانب العنوان)" @click="setPinned(false)"><PinOff class="size-3.5" /></button>
                    </div>
                    <TaskTree :tree="tree" :location="location" bare />
                </div>
            </aside>

            <section class="min-w-0">
                <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs text-slate-500">
                            <template v-if="loc?.client && loc.type !== 'client'"><Link :href="`/tasks?client=${loc.client.id}`" class="hover:underline">{{ loc.client.name }}</Link></template>
                            <template v-if="loc?.project && loc.type === 'list'"> ← <Link :href="`/tasks?project=${loc.project.id}`" class="hover:underline">{{ loc.project.name }}</Link></template>
                            <template v-if="!loc">كل ما يمكنك رؤيته</template>
                        </p>
                        <h1 class="flex items-center gap-2 text-2xl font-bold text-navy">
                            <Dropdown v-if="!pinned" width="w-80" panel-class="p-0" @open="treeKey++">
                                <template #trigger="{ open }">
                                    <button type="button" class="-mx-2 flex min-w-0 items-center gap-1.5 rounded-xl px-2 py-0.5 hover:bg-slate-100" title="اختر عميلاً أو مشروعاً أو قائمة">
                                        <span class="truncate">{{ title }}</span><ChevronDown class="size-5 shrink-0 text-slate-400 transition" :class="open ? 'rotate-180' : ''" />
                                    </button>
                                </template>
                                <template #default="{ close }">
                                    <div class="flex items-center justify-between border-b border-slate-100 px-3 py-2 text-xs text-slate-500">
                                        انتقل إلى
                                        <button type="button" class="flex items-center gap-1 rounded px-1.5 py-1 hover:bg-slate-100 hover:text-navy" title="تثبيت كلوحة جانبية" @click="setPinned(true); close()"><Pin class="size-3.5" /> تثبيت</button>
                                    </div>
                                    <div class="max-h-[65vh] overflow-y-auto">
                                        <TaskTree :key="treeKey" :tree="tree" :location="location" bare autofocus @pick="close()" />
                                    </div>
                                </template>
                            </Dropdown>
                            <span v-else class="truncate">{{ title }}</span>
                            <span v-if="loc?.type === 'client' && loc.prefix" class="num rounded-md bg-slate-100 px-2 py-0.5 text-sm font-semibold text-slate-500" dir="ltr">{{ loc.prefix }}</span>
                        </h1>
                        <p v-if="loc?.description" class="mt-1 text-sm text-slate-500">{{ loc.description }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Link v-if="loc?.type === 'project'" :href="`/projects/${loc.id}`" class="text-sm text-brand hover:underline">مساحة المشروع</Link>
                        <Btn v-if="can.new_list" variant="secondary" @click="openNewList"><ListPlus class="size-4" /> قائمة</Btn>
                        <Dropdown v-if="loc?.type === 'list' && can.manage_list" width="w-48">
                            <template #trigger><Btn variant="secondary" title="إعدادات القائمة"><Settings2 class="size-4" /></Btn></template>
                            <template #default="{ close }">
                                <button type="button" :class="menuItem" @click="openEditList(); close()"><Pencil class="size-4" /> تعديل القائمة</button>
                                <button type="button" :class="menuItem" @click="statusEditor = true; close()"><Palette class="size-4" /> الحالات</button>
                                <button v-if="canManageFields" type="button" :class="menuItem" @click="fieldsManager = true; close()"><Shapes class="size-4" /> الحقول المخصصة</button>
                                <button type="button" :class="menuItem + ' text-red-600 hover:bg-red-50'" @click="deleteList(); close()"><Trash2 class="size-4" /> حذف القائمة</button>
                            </template>
                        </Dropdown>
                        <Btn v-if="canManageFields && loc?.type !== 'list'" variant="secondary" title="الحقول المخصصة" @click="fieldsManager = true"><Shapes class="size-4" /></Btn>
                        <Btn v-if="can.share" variant="secondary" title="مشاركة مع الفريق أو العميل (ضيف)" @click="shareOpen = true"><Share2 class="size-4" /> مشاركة</Btn>
                        <Dropdown v-if="!$page.props.auth.user?.is_guest" width="w-56">
                            <template #trigger><Btn variant="secondary" title="القوالب والأتمتة"><LayoutTemplate class="size-4" /></Btn></template>
                            <template #default="{ close }">
                                <button v-if="can.create || can.new_list" type="button" :class="menuItem" @click="openTpl('apply'); close()"><LayoutTemplate class="size-4" /> إنشاء من قالب</button>
                                <button v-if="tplSource" type="button" :class="menuItem" @click="openTpl('save'); close()"><BookmarkPlus class="size-4" /> حفظ {{ loc.type === 'list' ? 'القائمة' : 'قوائم المشروع' }} كقالب</button>
                                <Link v-if="canManageFields" :href="automationsUrl" :class="menuItem"><Zap class="size-4" /> الأتمتة</Link>
                                <Link href="/tasks/forms" :class="menuItem"><FileInput class="size-4" /> نماذج الاستقبال</Link>
                            </template>
                        </Dropdown>
                        <Btn v-if="can.create" @click="newTask = true"><Plus class="size-4" /> مهمة</Btn>
                    </div>
                </div>

                <!-- View switcher and saved views -->
                <div class="mb-3 flex flex-wrap items-center gap-2 border-b border-slate-200 pb-2">
                    <button v-for="t in viewTypes" :key="t.key" type="button" class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm transition"
                        :class="!savedView && v.view === t.key ? 'bg-navy font-semibold text-white' : 'text-slate-600 hover:bg-slate-100'" @click="setView(t.key)">
                        <component :is="t.icon" class="size-4" /> {{ t.label }}
                    </button>
                    <span v-if="savedViews.length" class="mx-1 h-5 w-px bg-slate-200" />
                    <button v-for="sv in savedViews" :key="sv.id" type="button" class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm transition"
                        :class="savedView?.id === sv.id ? 'bg-brand font-semibold text-white' : 'text-slate-600 hover:bg-slate-100'" :title="sv.is_shared ? `مشترك · ${sv.owner}` : 'خاص بك'" @click="openSaved(sv)">
                        <Bookmark class="size-3.5" /> {{ sv.name }} <Users2 v-if="sv.is_shared" class="size-3 opacity-60" />
                    </button>
                    <div class="ms-auto flex items-center gap-1">
                        <Dropdown width="w-64">
                            <template #trigger><Btn variant="ghost" size="sm" title="إعدادات العرض"><SlidersHorizontal class="size-4" /> العرض</Btn></template>
                            <div class="space-y-3 p-2 text-sm">
                                <label v-if="v.view === 'list' || v.view === 'board'" class="block"><span class="mb-1 block text-xs text-slate-500">التجميع حسب</span>
                                    <select :value="v.group" class="field-input py-1.5" @change="go({ view: { group: $event.target.value } })">
                                        <option v-for="(l, k) in groupLabels" v-show="!(v.view === 'board' && (k === 'none' || k === 'list'))" :key="k" :value="k">{{ l }}</option>
                                        <option v-for="f in dropdownFields" :key="f.id" :value="`cf:${f.id}`">{{ f.name }}</option>
                                    </select></label>
                                <label v-if="v.view !== 'calendar'" class="block"><span class="mb-1 block text-xs text-slate-500">الترتيب</span>
                                    <div class="flex gap-1">
                                        <select :value="v.sort" class="field-input py-1.5" @change="go({ view: { sort: $event.target.value } })"><option v-for="(l, k) in sortLabels" :key="k" :value="k">{{ l }}</option>
                                            <option v-for="f in customFields.filter((x) => x.type !== 'formula')" :key="f.id" :value="`cf:${f.id}`">{{ f.name }}</option></select>
                                        <select :value="v.dir" class="field-input w-24 py-1.5" @change="go({ view: { dir: $event.target.value } })"><option value="asc">تصاعدي</option><option value="desc">تنازلي</option></select>
                                    </div></label>
                                <label v-if="v.view === 'calendar'" class="block"><span class="mb-1 block text-xs text-slate-500">الوضع على التقويم حسب</span>
                                    <select :value="v.date" class="field-input py-1.5" @change="go({ view: { date: $event.target.value } })"><option value="due">تاريخ التسليم</option><option value="start">تاريخ البدء</option></select></label>
                                <label class="flex items-center gap-2"><input type="checkbox" class="rounded" :checked="v.subtasks" @change="go({ view: { subtasks: $event.target.checked } })" /> إظهار المهام الفرعية</label>
                                <div v-if="v.view === 'table'"><span class="mb-1 block text-xs text-slate-500">الأعمدة</span>
                                    <button v-for="c in allColumns" :key="c" type="button" class="flex w-full items-center gap-2 rounded px-1 py-1 hover:bg-slate-50" @click="toggleColumn(c)">
                                        <Check class="size-3.5" :class="v.columns.includes(c) ? 'text-brand' : 'text-transparent'" /> {{ colLabel(c) }}
                                    </button></div>
                            </div>
                        </Dropdown>
                        <a v-if="v.view === 'table' || v.view === 'list'" :href="exportUrl" class="inline-flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-100" title="تصدير CSV (يفتح في Excel)"><Download class="size-4" /> تصدير</a>
                        <Dropdown v-if="savedView?.can_manage" width="w-56">
                            <template #trigger><Btn variant="ghost" size="sm"><Bookmark class="size-4" /> {{ savedView.name }}</Btn></template>
                            <template #default="{ close }">
                                <button type="button" :class="menuItem" @click="updateSaved(); close()"><Check class="size-4" /> حفظ التغييرات في العرض</button>
                                <button type="button" :class="menuItem" @click="toggleShare(); close()"><component :is="savedView.is_shared ? Lock : Users2" class="size-4" /> {{ savedView.is_shared ? 'جعله خاصاً' : 'مشاركته مع الفريق' }}</button>
                                <button type="button" :class="menuItem + ' text-red-600 hover:bg-red-50'" @click="deleteSaved(); close()"><Trash2 class="size-4" /> حذف العرض</button>
                            </template>
                        </Dropdown>
                        <Btn variant="ghost" size="sm" title="حفظ كعرض" @click="saveView = true"><BookmarkPlus class="size-4" /></Btn>
                    </div>
                </div>

                <div v-if="errors.length" class="mb-4 rounded-2xl bg-red-50 p-4 text-sm text-red-800"><p v-for="e in errors" :key="e">{{ e }}</p></div>

                <TaskFilters :filters="filters" :users="users" :priorities="priorities" :tags="tags" :statuses="statuses" :types="types" :custom-fields="customFields" @change="(f) => go({ filters: f })">
                    <span class="num">{{ top }}</span> مهمة<template v-if="tasks.length >= 1000"> (يُعرض أول 1000؛ ضيّق الفلاتر)</template>
                </TaskFilters>

                <TaskListView v-if="v.view === 'list'" :tasks="tasks" :statuses="statuses" :users="users" :tags="tags" :priorities="priorities" :can="can"
                    :list-id="listId" :show-location="loc?.type !== 'list'" :group-by="v.group" :types="types" :custom-fields="customFields" selectable :selected="selected" @toggle-select="toggleSelect" @select-many="selectMany" />
                <TaskBoardView v-else-if="v.view === 'board'" :tasks="tasks" :statuses="statuses" :users="users" :tags="tags" :priorities="priorities" :can="can"
                    :list-id="listId" :group-by="v.group === 'none' || v.group === 'list' ? 'status' : v.group" :manual="v.sort === 'manual'" :types="types" :custom-fields="customFields" />
                <TaskGanttView v-else-if="v.view === 'gantt'" :tasks="tasks" :can="can" :types="types" :export-url="$page.props.auth.user?.is_guest ? null : ganttExportUrl" />
                <TaskCalendarView v-else-if="v.view === 'calendar'" :tasks="tasks" :can="can" :list-id="listId" :date-field="v.date" />
                <TaskTableView v-else :tasks="tasks" :statuses="statuses" :users="users" :tags="tags" :priorities="priorities" :can="can"
                    :columns="v.columns" :sort="v.sort" :dir="v.dir" :types="types" :custom-fields="customFields" :selected="selected" @toggle-select="toggleSelect" @select-many="selectMany"
                    @sort="(s, d) => go({ view: { sort: s, dir: d } })" />

                <div v-if="!tasks.length && !['calendar', 'board', 'gantt'].includes(v.view)" class="card mt-4 p-10 text-center text-sm text-slate-500">
                    لا توجد مهام هنا<template v-if="Object.keys(filters).length"> تطابق الفلاتر</template>.
                </div>
            </section>
        </div>

        <BulkBar v-if="selected.length" :selected="selected" :statuses="statuses" :users="users" :tags="tags" :priorities="priorities" :lists="lists" :can="bulkCan" @clear="selected = []" />
        <StatusEditor v-if="loc?.type === 'list'" v-model="statusEditor" :statuses="statuses" :groups="groups" :url="`/tasks/lists/${loc.id}/statuses`" />
        <ListFormModal v-model="listForm" :list="editList" :client-id="newListClient" :project-id="newListProject" :milestones="milestones" :templates="schemeTemplates" />
        <NewTaskModal v-model="newTask" :tree="tree" :location="location" />
        <FieldsManager v-if="canManageFields" v-model="fieldsManager" :fields="customFields" :types="types" :location="location" />
        <ShareModal v-if="can.share" v-model="shareOpen" :location="location" />
        <TemplatesModal v-model="tplOpen" :mode="tplMode" :source="tplSource" :target="tplTarget" :templates="templates" :kinds="tplKinds" />
        <SaveViewModal v-model="saveView" :location="location" :view="view" :filters="filters" />
    </AppLayout>
</template>

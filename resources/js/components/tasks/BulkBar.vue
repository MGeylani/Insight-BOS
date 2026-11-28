<script setup>
// Bulk actions on the selected tasks.
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { X, CircleDot, Users, Flag, CalendarDays, Tag as TagIcon, FolderInput, ListPlus, Trash2 } from '@lucide/vue';
import Dropdown from '@/components/Dropdown.vue';
import Avatar from '@/components/Avatar.vue';
import { priorityColor, todayStr } from '@/lib/tasks';

const props = defineProps({ selected: Array, statuses: Array, users: Array, tags: Array, priorities: Object, lists: Array, can: Object });
const emit = defineEmits(['clear']);
const due = ref('');
const q = ref('');
const lists = computed(() => props.lists.filter((l) => !q.value || `${l.client} ${l.project ?? ''} ${l.name}`.includes(q.value)).slice(0, 60));

function run(action, value, close) {
    if (action === 'archive' && !confirm(`أرشفة ${props.selected.length} مهمة (ومهامها الفرعية)؟`)) return;
    router.post('/tasks/bulk', { ids: props.selected, action, value }, { preserveScroll: true, preserveState: true, onSuccess: () => emit('clear') });
    close?.();
}
const item = 'flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50';
const btn = 'flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm text-white/90 hover:bg-white/10';
</script>

<template>
    <div class="fixed inset-x-0 bottom-6 z-40 flex justify-center px-4 lg:ps-72">
        <div class="flex flex-wrap items-center gap-1 rounded-2xl bg-navy px-3 py-2 text-white shadow-2xl">
            <span class="num me-2 text-sm font-semibold">{{ selected.length }} محددة</span>
            <Dropdown v-if="can.edit" width="w-52"><template #trigger><button type="button" :class="btn"><CircleDot class="size-4" /> الحالة</button></template>
                <template #default="{ close }"><button v-for="s in statuses" :key="s.id" type="button" :class="item" @click="run('status', s.id, close)"><span class="size-2.5 rounded-full" :style="{ background: s.color }" />{{ s.name }}</button></template>
            </Dropdown>
            <Dropdown v-if="can.assign" width="w-60" @close="q = ''"><template #trigger><button type="button" :class="btn"><Users class="size-4" /> المسؤول</button></template>
                <template #default="{ close }">
                    <p class="px-2 pt-1 text-[11px] text-slate-400">إضافة مسؤول (يبقى الحاليون)</p>
                    <button v-for="u in users" :key="u.id" type="button" :class="item" @click="run('assignees_add', [u.id], close)"><Avatar :name="u.name" />{{ u.name }}</button>
                    <button type="button" :class="item + ' text-red-600'" @click="run('assignees_set', [], close)">إزالة كل المسؤولين</button>
                </template>
            </Dropdown>
            <Dropdown v-if="can.edit" width="w-40"><template #trigger><button type="button" :class="btn"><Flag class="size-4" /> الأولوية</button></template>
                <template #default="{ close }"><button v-for="(l, k) in priorities" :key="k" type="button" :class="item" @click="run('priority', k, close)"><Flag class="size-4" :style="{ color: priorityColor[k] }" />{{ l }}</button></template>
            </Dropdown>
            <Dropdown v-if="can.edit" width="w-56"><template #trigger><button type="button" :class="btn"><CalendarDays class="size-4" /> التسليم</button></template>
                <template #default="{ close }">
                    <div class="space-y-2 p-2">
                        <div class="flex gap-1">
                            <button type="button" class="rounded-lg bg-slate-100 px-2 py-1 text-xs" @click="run('due', { date: todayStr() }, close)">اليوم</button>
                            <button type="button" class="rounded-lg bg-slate-100 px-2 py-1 text-xs" @click="run('due', { date: todayStr(1) }, close)">غداً</button>
                            <button type="button" class="rounded-lg bg-slate-100 px-2 py-1 text-xs" @click="run('due', null, close)">بلا تاريخ</button>
                        </div>
                        <input v-model="due" type="date" dir="ltr" class="field-input py-1.5 text-sm" />
                        <button type="button" class="w-full rounded-lg bg-navy py-1 text-xs font-semibold text-white disabled:opacity-40" :disabled="!due" @click="run('due', { date: due }, close)">تطبيق</button>
                    </div>
                </template>
            </Dropdown>
            <Dropdown v-if="can.edit && tags.length" width="w-52"><template #trigger><button type="button" :class="btn"><TagIcon class="size-4" /> وسم</button></template>
                <template #default="{ close }">
                    <button v-for="t in tags" :key="t.id" type="button" :class="item" @click="run('tags_add', [t.id], close)"><span class="size-2.5 rounded-full" :style="{ background: t.color }" />{{ t.name }}</button>
                </template>
            </Dropdown>
            <Dropdown v-if="can.edit" width="w-72" @close="q = ''"><template #trigger><button type="button" :class="btn"><FolderInput class="size-4" /> نقل</button></template>
                <template #default="{ close }">
                    <input v-model="q" class="field-input mb-1 py-1.5 text-sm" placeholder="ابحث عن قائمة…" />
                    <p class="px-2 text-[11px] text-slate-400">تُنقل فقط المهام من العميل نفسه</p>
                    <button v-for="l in lists" :key="l.id" type="button" :class="item" @click="run('move', l.id, close)"><span class="truncate">{{ l.name }} <span class="text-xs text-slate-400">· {{ l.client }}<template v-if="l.project"> ← {{ l.project }}</template></span></span></button>
                </template>
            </Dropdown>
            <Dropdown v-if="can.edit" width="w-72" @close="q = ''"><template #trigger><button type="button" :class="btn"><ListPlus class="size-4" /> إضافة لقائمة</button></template>
                <template #default="{ close }">
                    <input v-model="q" class="field-input mb-1 py-1.5 text-sm" placeholder="ابحث عن قائمة…" />
                    <button v-for="l in lists" :key="l.id" type="button" :class="item" @click="run('link', l.id, close)"><span class="truncate">{{ l.name }} <span class="text-xs text-slate-400">· {{ l.client }}</span></span></button>
                </template>
            </Dropdown>
            <button v-if="can.delete" type="button" :class="btn + ' hover:bg-red-500/30'" @click="run('archive')"><Trash2 class="size-4" /> أرشفة</button>
            <button type="button" class="ms-1 rounded-lg p-1.5 text-white/70 hover:bg-white/10" title="إلغاء التحديد" @click="emit('clear')"><X class="size-4" /></button>
        </div>
    </div>
</template>

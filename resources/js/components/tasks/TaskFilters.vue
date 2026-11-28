<script setup>
// Filter bar shared by every view. Emits the full filter object on change.
import { computed, ref, watch } from 'vue';
import { Search, X, ListFilter, ChevronDown } from '@lucide/vue';
import Dropdown from '@/components/Dropdown.vue';

const props = defineProps({ filters: Object, users: Array, priorities: Object, tags: Array, statuses: Array, types: { type: Array, default: () => [] }, customFields: { type: Array, default: () => [] } });
const filterable = computed(() => props.customFields.filter((x) => ['dropdown', 'checkbox'].includes(x.type)));
const setField = (id, value) => { f.value.fields = { ...(f.value.fields ?? {}) }; if (value === '') delete f.value.fields[id]; else f.value.fields[id] = value; changed(); };
const emit = defineEmits(['change']);
const f = ref({});
watch(() => props.filters, (v) => (f.value = { search: '', due: '', ...JSON.parse(JSON.stringify(v ?? {})) }), { immediate: true, deep: true });

let t;
const changed = (debounce = false) => {
    clearTimeout(t);
    const go = () => emit('change', Object.fromEntries(Object.entries(f.value).filter(([, v]) => v !== '' && v !== null && v !== false && !(Array.isArray(v) && !v.length))));
    debounce ? (t = setTimeout(go, 350)) : go();
};
const single = (key, value) => { f.value[key] = value ? [value] : []; changed(); };
const first = (key) => (f.value[key] ?? [])[0] ?? '';
const reset = () => { f.value = { search: '', due: '' }; changed(); };
const active = () => Object.keys(props.filters ?? {}).length > 0;
// Filters inside the «فلاتر» menu that are set (search and «show completed» sit outside it).
const menuCount = computed(() => Object.entries(props.filters ?? {}).filter(([k, v]) => !['search', 'closed'].includes(k) && !(v && typeof v === 'object' && !Array.isArray(v) && !Object.keys(v).length)).length);
</script>

<template>
    <div class="mb-3 flex flex-wrap items-center gap-2">
        <div class="relative">
            <Search class="absolute start-2.5 top-2 size-4 text-slate-400" />
            <input v-model="f.search" class="field-input w-56 py-1.5 ps-8 text-sm" placeholder="بحث أو معرّف (ULF-12)…" @input="changed(true)" />
        </div>
        <Dropdown width="w-72" panel-class="max-h-[70vh] overflow-y-auto p-3">
            <template #trigger>
                <button type="button" class="inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-sm transition"
                    :class="menuCount ? 'border-brand bg-brand/5 font-semibold text-brand' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'">
                    <ListFilter class="size-4" /> فلاتر<span v-if="menuCount" class="num">({{ menuCount }})</span><ChevronDown class="size-3.5 opacity-60" />
                </button>
            </template>
            <div class="space-y-2.5 text-sm">
                <label class="block"><span class="mb-1 block text-xs text-slate-500">المسؤول</span>
                    <select :value="first('assignees')" class="field-input py-1.5" @change="single('assignees', $event.target.value)">
                        <option value="">كل المسؤولين</option><option value="me">مهامي</option><option value="none">بلا مسؤول</option>
                        <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select></label>
                <label class="block"><span class="mb-1 block text-xs text-slate-500">التاريخ</span>
                    <select v-model="f.due" class="field-input py-1.5" @change="changed()">
                        <option value="">كل التواريخ</option><option value="overdue">متأخرة</option><option value="today">اليوم</option>
                        <option value="next7">الأيام السبعة القادمة</option><option value="week">هذا الأسبوع</option><option value="none">بلا تاريخ</option>
                    </select></label>
                <label class="block"><span class="mb-1 block text-xs text-slate-500">الأولوية</span>
                    <select :value="first('priorities')" class="field-input py-1.5" @change="single('priorities', $event.target.value)">
                        <option value="">كل الأولويات</option><option v-for="(l, k) in priorities" :key="k" :value="k">{{ l }}</option>
                    </select></label>
                <label v-if="tags.length" class="block"><span class="mb-1 block text-xs text-slate-500">الوسم</span>
                    <select :value="first('tags')" class="field-input py-1.5" @change="single('tags', $event.target.value)">
                        <option value="">كل الوسوم</option><option v-for="t in tags" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select></label>
                <label v-if="types.length > 1" class="block"><span class="mb-1 block text-xs text-slate-500">النوع</span>
                    <select :value="first('types')" class="field-input py-1.5" @change="single('types', $event.target.value)">
                        <option value="">كل الأنواع</option><option v-for="t in types" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select></label>
                <label v-for="cf in filterable" :key="cf.id" class="block"><span class="mb-1 block text-xs text-slate-500">{{ cf.name }}</span>
                    <select :value="f.fields?.[cf.id] ?? ''" class="field-input py-1.5" @change="setField(cf.id, $event.target.value)">
                        <option value="">الكل</option>
                        <template v-if="cf.type === 'dropdown'"><option v-for="c in cf.options.choices" :key="c.id" :value="c.id">{{ c.label }}</option></template>
                        <template v-else><option value="1">نعم</option><option value="0">لا</option></template>
                    </select></label>
                <button v-if="menuCount" type="button" class="flex items-center gap-1 pt-1 text-xs text-slate-500 hover:text-red-600" @click="reset"><X class="size-3.5" /> مسح كل الفلاتر</button>
            </div>
        </Dropdown>
        <label class="flex items-center gap-1.5 text-sm text-slate-600"><input v-model="f.closed" type="checkbox" class="rounded" @change="changed()" /> إظهار المكتملة</label>
        <button v-if="active()" type="button" class="flex items-center gap-1 text-xs text-slate-500 hover:text-red-600" @click="reset"><X class="size-3.5" /> مسح</button>
        <span class="ms-auto text-xs text-slate-500"><slot /></span>
    </div>
</template>

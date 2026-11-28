<script setup>
// Filter bar shared by every view. Emits the full filter object on change.
import { ref, watch } from 'vue';
import { Search, X } from '@lucide/vue';

const props = defineProps({ filters: Object, users: Array, priorities: Object, tags: Array, statuses: Array });
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
</script>

<template>
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <div class="relative">
            <Search class="absolute start-2.5 top-2.5 size-4 text-slate-400" />
            <input v-model="f.search" class="field-input w-56 ps-8" placeholder="بحث أو معرّف (ULF-12)…" @input="changed(true)" />
        </div>
        <select :value="first('assignees')" class="field-input w-auto" @change="single('assignees', $event.target.value)">
            <option value="">كل المسؤولين</option><option value="me">مهامي</option><option value="none">بلا مسؤول</option>
            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
        </select>
        <select v-model="f.due" class="field-input w-auto" @change="changed()">
            <option value="">كل التواريخ</option><option value="overdue">متأخرة</option><option value="today">اليوم</option>
            <option value="next7">الأيام السبعة القادمة</option><option value="week">هذا الأسبوع</option><option value="none">بلا تاريخ</option>
        </select>
        <select :value="first('priorities')" class="field-input w-auto" @change="single('priorities', $event.target.value)">
            <option value="">كل الأولويات</option><option v-for="(l, k) in priorities" :key="k" :value="k">{{ l }}</option>
        </select>
        <select v-if="tags.length" :value="first('tags')" class="field-input w-auto" @change="single('tags', $event.target.value)">
            <option value="">كل الوسوم</option><option v-for="t in tags" :key="t.id" :value="t.id">{{ t.name }}</option>
        </select>
        <label class="flex items-center gap-1.5 text-sm text-slate-600"><input v-model="f.closed" type="checkbox" class="rounded" @change="changed()" /> إظهار المكتملة</label>
        <button v-if="active()" type="button" class="flex items-center gap-1 text-xs text-slate-500 hover:text-red-600" @click="reset"><X class="size-3.5" /> مسح الفلاتر</button>
    </div>
</template>

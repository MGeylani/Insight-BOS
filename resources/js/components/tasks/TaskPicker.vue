<script setup>
// Search tasks by title or id (ULF-12) and pick one.
import { ref } from 'vue';
import { Search } from '@lucide/vue';

const props = defineProps({ exclude: Number, placeholder: { type: String, default: 'ابحث بالعنوان أو المعرّف…' } });
const emit = defineEmits(['pick']);
const q = ref('');
const results = ref([]);
let t;
function search() {
    clearTimeout(t);
    t = setTimeout(async () => {
        const r = await fetch(`/tasks/lookup?q=${encodeURIComponent(q.value)}&exclude=${props.exclude ?? ''}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        results.value = r.ok ? await r.json() : [];
    }, 250);
}
search();
</script>

<template>
    <div>
        <div class="relative mb-1">
            <Search class="absolute start-2 top-2 size-4 text-slate-400" />
            <input v-model="q" class="field-input py-1.5 ps-8 text-sm" :placeholder="placeholder" autofocus @input="search" />
        </div>
        <button v-for="r in results" :key="r.id" type="button" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-start text-sm hover:bg-slate-50" @click="emit('pick', r)">
            <span class="num shrink-0 text-xs text-slate-400" dir="ltr">{{ r.code }}</span>
            <span class="truncate" :class="r.completed ? 'text-slate-400 line-through' : ''">{{ r.title }}</span>
        </button>
        <p v-if="!results.length" class="px-2 py-2 text-xs text-slate-500">لا نتائج.</p>
    </div>
</template>

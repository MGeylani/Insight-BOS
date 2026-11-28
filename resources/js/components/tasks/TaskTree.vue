<script setup>
// Sidebar: Insight Media first, then clients; each with its own lists and its projects' lists.
import { computed, reactive, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ChevronDown, ChevronLeft, Building2, Briefcase, List, Layers, Search } from '@lucide/vue';

const props = defineProps({ tree: Array, location: Object });
const q = ref('');
const open = reactive({});
const isOpen = (key, def) => (open[key] ?? def);
const here = (type, id) => props.location?.type === type && props.location?.id === id;

const filtered = computed(() => {
    const s = q.value.trim();
    if (!s) return props.tree;
    const m = (t) => (t ?? '').includes(s);
    return props.tree.map((c) => ({
        ...c,
        lists: m(c.name) ? c.lists : c.lists.filter((l) => m(l.name)),
        projects: c.projects.map((p) => ({ ...p, lists: m(c.name) || m(p.name) ? p.lists : p.lists.filter((l) => m(l.name)) })).filter((p) => p.lists.length),
    })).filter((c) => c.lists.length || c.projects.length);
});
const contains = (c) => props.location && (
    (props.location.type === 'client' && props.location.id === c.id)
    || c.lists.some((l) => here('list', l.id))
    || c.projects.some((p) => here('project', p.id) || p.lists.some((l) => here('list', l.id))));
</script>

<template>
    <div class="card p-3 text-sm">
        <Link href="/tasks" class="mb-2 flex items-center gap-2 rounded-lg px-2 py-1.5 font-semibold" :class="!location ? 'bg-navy text-white' : 'text-navy hover:bg-slate-100'">
            <Layers class="size-4" /> كل المهام
        </Link>
        <div class="relative mb-2">
            <Search class="absolute start-2 top-2 size-3.5 text-slate-400" />
            <input v-model="q" class="field-input py-1 ps-7 text-xs" placeholder="بحث في القوائم…" />
        </div>
        <ul class="space-y-0.5">
            <li v-for="c in filtered" :key="c.id">
                <div class="flex items-center gap-1">
                    <button type="button" class="rounded p-0.5 text-slate-400 hover:bg-slate-100" @click="open['c' + c.id] = !isOpen('c' + c.id, c.internal || contains(c) || !!q)">
                        <ChevronDown v-if="isOpen('c' + c.id, c.internal || contains(c) || !!q)" class="size-3.5" /><ChevronLeft v-else class="size-3.5" />
                    </button>
                    <Link :href="`/tasks?client=${c.id}`" class="flex min-w-0 flex-1 items-center gap-1.5 rounded-lg px-1.5 py-1" :class="here('client', c.id) ? 'bg-navy font-semibold text-white' : 'font-medium text-slate-700 hover:bg-slate-100'">
                        <Building2 class="size-3.5 shrink-0" /><span class="truncate">{{ c.name }}</span>
                        <span class="num ms-auto text-[10px] opacity-60" dir="ltr">{{ c.prefix }}</span>
                    </Link>
                </div>
                <ul v-if="isOpen('c' + c.id, c.internal || contains(c) || !!q)" class="ms-4 space-y-0.5 border-s border-slate-100 ps-1">
                    <li v-for="l in c.lists" :key="'l' + l.id">
                        <Link :href="`/tasks?list=${l.id}`" class="flex items-center gap-1.5 rounded-lg px-1.5 py-1" :class="here('list', l.id) ? 'bg-brand/10 font-semibold text-brand' : 'text-slate-600 hover:bg-slate-100'">
                            <List class="size-3.5 shrink-0" /><span class="truncate">{{ l.name }}</span><span v-if="l.open" class="num ms-auto text-[10px] text-slate-400">{{ l.open }}</span>
                        </Link>
                    </li>
                    <li v-for="p in c.projects" :key="'p' + p.id">
                        <Link :href="`/tasks?project=${p.id}`" class="flex items-center gap-1.5 rounded-lg px-1.5 py-1" :class="here('project', p.id) ? 'bg-brand/10 font-semibold text-brand' : 'text-slate-700 hover:bg-slate-100'">
                            <Briefcase class="size-3.5 shrink-0" /><span class="truncate">{{ p.name }}</span>
                        </Link>
                        <ul class="ms-4 space-y-0.5 border-s border-slate-100 ps-1">
                            <li v-for="l in p.lists" :key="'pl' + l.id">
                                <Link :href="`/tasks?list=${l.id}`" class="flex items-center gap-1.5 rounded-lg px-1.5 py-0.5 text-xs" :class="here('list', l.id) ? 'bg-brand/10 font-semibold text-brand' : 'text-slate-600 hover:bg-slate-100'">
                                    <List class="size-3 shrink-0" /><span class="truncate">{{ l.name }}</span><span v-if="l.open" class="num ms-auto text-[10px] text-slate-400">{{ l.open }}</span>
                                </Link>
                            </li>
                        </ul>
                    </li>
                </ul>
            </li>
        </ul>
        <p v-if="!filtered.length" class="px-2 py-3 text-xs text-slate-500">لا توجد قوائم.</p>
    </div>
</template>

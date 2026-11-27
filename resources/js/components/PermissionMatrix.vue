<script setup>
import { computed } from 'vue';

const model = defineModel({ type: Object, default: () => ({}) });
const props = defineProps({
    catalog: { type: Array, required: true },
    scopes: { type: Object, required: true },
    readonly: Boolean,
});

const scopeKeys = computed(() => Object.keys(props.scopes));

function setScope(key, scope) {
    const next = { ...model.value };
    if (scope) next[key] = scope;
    else delete next[key];
    model.value = next;
}

function setModule(mod, scope) {
    const next = { ...model.value };
    for (const a of mod.actions) {
        if (scope) next[a.key] = scope;
        else delete next[a.key];
    }
    model.value = next;
}

const countFor = (mod) => mod.actions.filter((a) => model.value[a.key]).length;

const tone = (scope) => ({
    all: 'border-navy bg-navy text-white',
    assigned: 'border-brand bg-brand/10 text-brand',
    own: 'border-sky bg-sky/10 text-[#00729a]',
})[scope] ?? 'border-slate-200 bg-white text-slate-500';
</script>

<template>
    <div class="overflow-hidden rounded-2xl border border-slate-200">
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 bg-slate-50 px-4 py-2.5 text-xs text-slate-500">
            <span class="font-semibold text-slate-600">النطاق:</span>
            <span v-for="(label, key) in scopes" :key="key" class="inline-flex items-center gap-1.5">
                <span class="size-2.5 rounded-full border" :class="tone(key)" /> {{ label }}
            </span>
        </div>
        <div class="divide-y divide-slate-100">
            <div v-for="mod in catalog" :key="mod.key" class="grid gap-3 px-4 py-3.5 lg:grid-cols-[200px_1fr]">
                <div class="flex items-start justify-between gap-2 lg:block">
                    <p class="font-semibold text-slate-800">{{ mod.label }}</p>
                    <p class="text-xs text-slate-400">{{ countFor(mod) }} من {{ mod.actions.length }}</p>
                    <div v-if="!readonly" class="mt-1.5 flex gap-2 text-xs">
                        <button type="button" class="text-brand hover:underline" @click="setModule(mod, 'all')">الكل</button>
                        <button type="button" class="text-slate-400 hover:text-red-600 hover:underline" @click="setModule(mod, null)">إزالة</button>
                    </div>
                </div>
                <div class="flex flex-wrap items-start gap-2">
                    <div
                        v-for="a in mod.actions"
                        :key="a.key"
                        class="flex items-center gap-1.5 rounded-xl border py-1 ps-3 pe-1 text-sm transition"
                        :class="tone(model[a.key])"
                    >
                        <span>{{ a.label }}</span>
                        <select
                            v-if="!readonly"
                            :value="model[a.key] ?? ''"
                            class="rounded-lg border-0 bg-white/80 py-0.5 ps-2 pe-7 text-xs text-slate-700 focus:ring-2 focus:ring-brand/30"
                            :aria-label="`${mod.label} - ${a.label}`"
                            @change="setScope(a.key, $event.target.value)"
                        >
                            <option value="">بدون</option>
                            <option v-for="s in scopeKeys" :key="s" :value="s">{{ scopes[s] }}</option>
                        </select>
                        <span v-else-if="model[a.key]" class="pe-2 text-xs opacity-80">· {{ scopes[model[a.key]] }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

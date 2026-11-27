<script setup>
defineProps({ tone: { type: String, default: 'slate' } });
const tones = {
    slate: 'bg-slate-100 text-slate-600',
    green: 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-600/15',
    red: 'bg-red-50 text-red-700 ring-1 ring-red-600/15',
    amber: 'bg-amber-50 text-amber-800 ring-1 ring-amber-600/20',
    navy: 'bg-navy-50 text-navy ring-1 ring-navy/10',
    sky: 'bg-sky/10 text-[#00729a] ring-1 ring-sky/30',
    plum: 'bg-orchid/10 text-plum ring-1 ring-orchid/20',
};
</script>

<template>
    <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium" :class="tones[tone]"><slot /></span>
</template>

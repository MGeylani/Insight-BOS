<script setup>
import { Link } from '@inertiajs/vue3';
defineProps({ links: Array, meta: Object });
const label = (l) => l.replace('&laquo; Previous', 'السابق').replace('Next &raquo;', 'التالي').replace(/&laquo;|&raquo;/g, '');
</script>

<template>
    <div v-if="links && links.length > 3" class="flex flex-wrap items-center gap-1 px-4 py-3">
        <template v-for="(l, i) in links" :key="i">
            <Link v-if="l.url" :href="l.url" preserve-scroll class="rounded-lg px-3 py-1.5 text-sm" :class="l.active ? 'bg-navy text-white' : 'text-slate-600 hover:bg-slate-100'">{{ label(l.label) }}</Link>
            <span v-else class="px-3 py-1.5 text-sm text-slate-300">{{ label(l.label) }}</span>
        </template>
    </div>
</template>

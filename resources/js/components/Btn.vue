<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    variant: { type: String, default: 'primary' }, // primary | secondary | ghost | danger | sky
    size: { type: String, default: 'md' },
    href: { type: String, default: null },
    type: { type: String, default: 'button' },
    loading: Boolean,
    disabled: Boolean,
});

const classes = computed(() => [
    'inline-flex items-center justify-center gap-2 rounded-xl font-medium transition focus:outline-none focus-visible:ring-4 disabled:cursor-not-allowed disabled:opacity-50 whitespace-nowrap',
    props.size === 'sm' ? 'px-3 py-1.5 text-xs' : 'px-4 py-2.5 text-sm',
    {
        primary: 'bg-navy text-white hover:bg-navy-700 focus-visible:ring-navy/25 shadow-sm',
        sky: 'bg-sky text-navy-900 hover:brightness-95 focus-visible:ring-sky/30',
        secondary: 'border border-slate-300 bg-white text-slate-700 hover:border-slate-400 hover:bg-slate-50 focus-visible:ring-slate-300/40',
        ghost: 'text-slate-600 hover:bg-slate-100 focus-visible:ring-slate-300/40',
        danger: 'bg-red-600 text-white hover:bg-red-700 focus-visible:ring-red-500/25',
    }[props.variant],
]);
</script>

<template>
    <Link v-if="href" :href="href" :class="classes"><slot /></Link>
    <button v-else :type="type" :class="classes" :disabled="disabled || loading">
        <svg v-if="loading" class="size-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity=".25" stroke-width="4" /><path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="4" /></svg>
        <slot />
    </button>
</template>

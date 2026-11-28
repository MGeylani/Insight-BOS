<script setup>
import { computed } from 'vue';
import { initials } from '@/lib/format';

const props = defineProps({ name: String, size: { type: String, default: 'size-6 text-[10px]' } });
const palette = ['#00027C', '#0082F6', '#481C4E', '#864690', '#0e7490', '#b45309', '#047857', '#be123c'];
const color = computed(() => palette[[...(props.name ?? '')].reduce((a, c) => a + c.charCodeAt(0), 0) % palette.length]);
</script>

<template>
    <span class="inline-flex shrink-0 items-center justify-center rounded-full font-semibold text-white ring-2 ring-white" :class="size" :style="{ background: color }" :title="name">{{ initials(name) }}</span>
</template>

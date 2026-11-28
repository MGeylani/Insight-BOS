<script setup>
// Spend vs plan: green below the alert, amber from it, red past 100%. Optional tick for the share of time elapsed.
import { computed } from 'vue';
import { formatNumber } from '@/lib/format';

const props = defineProps({ percent: Number, alert: { type: Number, default: 90 }, elapsed: { type: Number, default: null } });
const color = computed(() => (props.percent >= 100 ? 'bg-red-500' : props.percent >= props.alert ? 'bg-amber-500' : 'bg-emerald-500'));
</script>

<template>
    <div class="flex items-center gap-2">
        <div class="relative h-2.5 flex-1 overflow-hidden rounded-full bg-slate-100">
            <div class="h-full rounded-full transition-all" :class="color" :style="{ width: Math.min(100, percent) + '%' }" />
            <div v-if="elapsed !== null" class="absolute inset-y-0 w-0.5 bg-navy/50" :style="{ insetInlineStart: Math.min(100, elapsed) + '%' }" title="مضى من الفترة" />
        </div>
        <span class="num w-12 text-end text-xs font-semibold" :class="percent >= 100 ? 'text-red-600' : 'text-slate-600'">{{ formatNumber(percent, 0) }}%</span>
    </div>
</template>

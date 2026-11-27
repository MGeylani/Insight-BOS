<script setup>
const model = defineModel({ type: Boolean, default: false });
defineProps({ label: String, disabled: Boolean });
</script>

<template>
    <label class="inline-flex cursor-pointer items-center gap-3 select-none" :class="{ 'cursor-not-allowed opacity-60': disabled }">
        <button
            type="button"
            role="switch"
            :aria-checked="model"
            :disabled="disabled"
            class="relative h-6 w-11 shrink-0 rounded-full transition"
            :class="model ? 'bg-brand' : 'bg-slate-300'"
            @click="model = !model"
        >
            <span class="absolute top-0.5 size-5 rounded-full bg-white shadow transition-all" :class="model ? 'start-[22px]' : 'start-0.5'" />
        </button>
        <span v-if="label" class="text-sm text-slate-700">{{ label }}</span>
    </label>
</template>

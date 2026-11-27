<script setup>
defineProps({ title: String, text: String });
</script>

<template>
    <div class="flex flex-col items-center justify-center px-6 py-14 text-center">
        <img src="/images/logo-mark.svg" alt="" class="mb-4 h-10 opacity-30" />
        <p class="font-semibold text-slate-700">{{ title }}</p>
        <p v-if="text" class="mt-1 max-w-sm text-sm text-slate-500">{{ text }}</p>
        <div class="mt-4"><slot /></div>
    </div>
</template>

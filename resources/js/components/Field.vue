<script setup>
defineProps({ label: String, error: String, hint: String, id: String, required: Boolean });
</script>

<template>
    <div>
        <label v-if="label" :for="id" class="field-label">{{ label }}<span v-if="required" class="text-red-500"> *</span></label>
        <slot />
        <p v-if="error" class="field-error">{{ error }}</p>
        <p v-else-if="hint" class="mt-1 text-xs text-slate-500">{{ hint }}</p>
    </div>
</template>

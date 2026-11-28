<script setup>
import { computed } from 'vue';
import { typeIcons } from '@/lib/tasks';

const props = defineProps({ types: { type: Array, default: () => [] }, typeId: Number, size: { type: String, default: 'size-3.5' }, defaultHidden: { type: Boolean, default: true } });
const type = computed(() => props.types.find((t) => t.id === props.typeId));
</script>

<template>
    <component :is="typeIcons[type.icon] ?? typeIcons['circle-check']" v-if="type && !(defaultHidden && type.is_default)" class="shrink-0" :class="size" :style="{ color: type.color }" :title="type.name" />
</template>

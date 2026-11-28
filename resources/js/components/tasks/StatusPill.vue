<script setup>
import { computed } from 'vue';
import { Check } from '@lucide/vue';
import Dropdown from '@/components/Dropdown.vue';

const props = defineProps({ status: Object, statuses: { type: Array, default: () => [] }, editable: Boolean, compact: Boolean });
const emit = defineEmits(['change']);
const groups = { not_started: 'لم تبدأ', active: 'نشطة', done: 'مكتملة', closed: 'مغلقة' };
const byGroup = computed(() => Object.keys(groups).map((g) => ({ g, rows: props.statuses.filter((s) => s.group === g) })).filter((x) => x.rows.length));
</script>

<template>
    <Dropdown :disabled="!editable" width="w-52">
        <template #trigger>
            <button type="button" class="inline-flex max-w-full items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-semibold text-white disabled:cursor-default"
                :class="compact ? 'px-1.5' : ''" :style="{ background: status?.color ?? '#94a3b8' }" :disabled="!editable">
                <span class="truncate">{{ status?.name ?? '—' }}</span>
            </button>
        </template>
        <template #default="{ close }">
            <template v-for="b in byGroup" :key="b.g">
                <p class="px-2 pb-1 pt-2 text-[10px] font-semibold text-slate-400">{{ groups[b.g] }}</p>
                <button v-for="s in b.rows" :key="s.id" type="button" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50"
                    @click="emit('change', s); close()">
                    <span class="size-2.5 shrink-0 rounded-full" :style="{ background: s.color }" />
                    <span class="flex-1 text-start">{{ s.name }}</span>
                    <Check v-if="status?.id === s.id" class="size-4 text-brand" />
                </button>
            </template>
        </template>
    </Dropdown>
</template>

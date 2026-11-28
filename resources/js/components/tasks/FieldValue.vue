<script setup>
// One custom field value: shows it, and edits it in place when editable. Emits `save` with the new value.
import { computed, ref } from 'vue';
import { Check, Star, ExternalLink } from '@lucide/vue';
import Dropdown from '@/components/Dropdown.vue';
import PeoplePicker from './PeoplePicker.vue';
import DueInput from './DueInput.vue';
import { fieldText } from '@/lib/tasks';

const props = defineProps({ field: Object, value: { default: null }, users: { type: Array, default: () => [] }, files: { type: Array, default: () => [] }, editable: Boolean, compact: Boolean });
const emit = defineEmits(['save']);
const draft = ref('');
const choices = computed(() => props.field.options?.choices ?? []);
const text = computed(() => fieldText(props.field, props.value, props.users));
const save = (v) => emit('save', v);
const startText = () => { draft.value = props.value ?? ''; };
const commitText = () => { const v = draft.value === '' ? null : draft.value; if (v !== (props.value ?? null)) save(v); };
const toggleLabel = (id) => { const cur = props.value ?? []; save(cur.includes(id) ? cur.filter((x) => x !== id) : [...cur, id]); };
const toggleFile = (id) => { const cur = props.value ?? []; save(cur.includes(id) ? cur.filter((x) => x !== id) : [...cur, id]); };
const input = 'w-full rounded-md border border-transparent bg-transparent px-1 py-0.5 text-sm hover:border-slate-200 focus:border-brand focus:outline-none';
</script>

<template>
    <span v-if="field.type === 'formula' || !editable" class="text-sm text-slate-700" :class="{ 'num font-semibold': ['formula', 'number', 'money', 'percent'].includes(field.type) }">
        <a v-if="field.type === 'url' && value" :href="value" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-brand hover:underline">رابط <ExternalLink class="size-3" /></a>
        <template v-else>{{ text || (compact ? '' : '—') }}</template>
    </span>

    <input v-else-if="['text', 'url'].includes(field.type)" :value="value ?? ''" :dir="field.type === 'url' ? 'ltr' : 'auto'" :class="input" :placeholder="compact ? '' : '—'"
        @focus="startText" @input="draft = $event.target.value" @blur="commitText" @keydown.enter="$event.target.blur()" />
    <input v-else-if="['number', 'money', 'percent'].includes(field.type)" :value="value ?? ''" type="number" step="any" dir="ltr" :class="input + ' num'" :placeholder="field.type === 'money' ? field.options?.currency : (field.type === 'percent' ? '%' : '')"
        @focus="startText" @input="draft = $event.target.value" @blur="commitText" @keydown.enter="$event.target.blur()" />
    <input v-else-if="field.type === 'checkbox'" type="checkbox" class="size-4 rounded border-slate-300 text-brand" :checked="!!value" @change="save($event.target.checked)" />
    <span v-else-if="field.type === 'rating'" class="inline-flex">
        <button v-for="i in field.options?.max ?? 5" :key="i" type="button" class="p-0.5" @click="save(value === i ? null : i)"><Star class="size-4" :class="i <= (value ?? 0) ? 'fill-amber-400 text-amber-400' : 'text-slate-300'" /></button>
    </span>
    <DueInput v-else-if="field.type === 'date'" :model-value="value" editable placeholder="" @update:model-value="save" />
    <PeoplePicker v-else-if="field.type === 'people'" :model-value="value ?? []" :users="users" editable @update:model-value="save" />
    <Dropdown v-else-if="['dropdown', 'labels', 'file'].includes(field.type)" width="w-56">
        <template #trigger>
            <button type="button" class="flex min-h-6 flex-wrap items-center gap-1 rounded-md px-1 text-start text-xs hover:bg-slate-100">
                <template v-if="field.type === 'dropdown' && value">
                    <span class="rounded px-1.5 py-0.5 font-semibold text-white" :style="{ background: choices.find((c) => c.id === value)?.color }">{{ text }}</span>
                </template>
                <template v-else-if="field.type === 'labels' && value?.length">
                    <span v-for="id in value" :key="id" class="rounded px-1.5 py-0.5 font-medium" :style="{ background: (choices.find((c) => c.id === id)?.color ?? '#64748b') + '22', color: choices.find((c) => c.id === id)?.color }">{{ choices.find((c) => c.id === id)?.label }}</span>
                </template>
                <span v-else-if="field.type === 'file' && value?.length" class="text-slate-700">{{ text }}</span>
                <span v-else class="text-slate-400">{{ compact ? '' : '—' }}</span>
            </button>
        </template>
        <template #default="{ close }">
            <template v-if="field.type === 'file'">
                <p v-if="!files.length" class="px-2 py-2 text-xs text-slate-500">ارفع ملفاً في «المرفقات» أولاً ثم اختره هنا.</p>
                <button v-for="a in files" :key="a.id" type="button" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50" @click="toggleFile(a.id)">
                    <span class="flex-1 truncate text-start">{{ a.name }}</span><Check v-if="(value ?? []).includes(a.id)" class="size-4 text-brand" />
                </button>
            </template>
            <template v-else>
                <button v-if="field.type === 'dropdown' && value" type="button" class="block w-full rounded-lg px-2 py-1.5 text-start text-sm text-slate-500 hover:bg-slate-50" @click="save(null); close()">بلا قيمة</button>
                <button v-for="c in choices" :key="c.id" type="button" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50"
                    @click="field.type === 'dropdown' ? (save(c.id), close()) : toggleLabel(c.id)">
                    <span class="size-2.5 rounded-full" :style="{ background: c.color }" /><span class="flex-1 text-start">{{ c.label }}</span>
                    <Check v-if="field.type === 'dropdown' ? value === c.id : (value ?? []).includes(c.id)" class="size-4 text-brand" />
                </button>
            </template>
        </template>
    </Dropdown>
</template>

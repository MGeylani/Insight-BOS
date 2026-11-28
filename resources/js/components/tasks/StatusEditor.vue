<script setup>
// Edit an ordered set of statuses. Removing a status asks where its tasks go.
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2, ChevronUp, ChevronDown } from '@lucide/vue';
import Modal from '@/components/Modal.vue';
import Btn from '@/components/Btn.vue';

const open = defineModel({ type: Boolean, default: false });
const props = defineProps({ statuses: Array, groups: Object, url: String, title: { type: String, default: 'حالات القائمة' }, withDefault: Boolean, isDefault: Boolean });
const rows = ref([]);
const moveTo = ref({});
const makeDefault = ref(false);
const form = useForm({});
watch(open, (v) => {
    if (!v) return;
    rows.value = props.statuses.map((s) => ({ ...s }));
    moveTo.value = {};
    makeDefault.value = props.isDefault;
    form.clearErrors();
});
const removed = computed(() => props.statuses.filter((s) => !rows.value.some((r) => r.id === s.id)));
const targets = computed(() => rows.value.map((r, i) => ({ key: r.id ?? `new:${i}`, name: r.name })).filter((t) => t.name.trim()));
const add = () => rows.value.push({ id: null, name: '', color: '#64748b', group: 'active' });
const move = (i, d) => { const r = rows.value.splice(i, 1)[0]; rows.value.splice(i + d, 0, r); };
function save() {
    form.transform(() => ({
        statuses: rows.value.map((r) => ({ id: r.id, name: r.name, color: r.color, group: r.group })),
        move_to: moveTo.value,
        is_default: makeDefault.value,
    })).put(props.url, { preserveScroll: true, onSuccess: () => (open.value = false) });
}
</script>

<template>
    <Modal v-model="open" :title="title" wide>
        <p class="mb-4 text-sm text-slate-500">كل حالة تتبع مجموعة: «مكتملة» و«مغلقة» تعني أن المهمة منتهية وتُحسب في التقدم.</p>
        <div class="space-y-2">
            <div v-for="(r, i) in rows" :key="r.id ?? 'n' + i" class="flex items-center gap-2">
                <input v-model="r.color" type="color" class="h-9 w-10 shrink-0 cursor-pointer rounded-lg border border-slate-200 bg-white p-1" />
                <input v-model="r.name" class="field-input flex-1" placeholder="اسم الحالة" />
                <select v-model="r.group" class="field-input w-32"><option v-for="(l, k) in groups" :key="k" :value="k">{{ l }}</option></select>
                <button type="button" class="rounded p-1 text-slate-400 hover:bg-slate-100 disabled:opacity-30" :disabled="i === 0" @click="move(i, -1)"><ChevronUp class="size-4" /></button>
                <button type="button" class="rounded p-1 text-slate-400 hover:bg-slate-100 disabled:opacity-30" :disabled="i === rows.length - 1" @click="move(i, 1)"><ChevronDown class="size-4" /></button>
                <button type="button" class="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600" @click="rows.splice(i, 1)"><Trash2 class="size-4" /></button>
            </div>
        </div>
        <Btn class="mt-3" size="sm" variant="secondary" @click="add"><Plus class="size-4" /> حالة</Btn>
        <div v-if="removed.length" class="mt-5 rounded-xl bg-amber-50 p-3 text-sm">
            <p class="mb-2 font-semibold text-amber-900">مهام الحالات المحذوفة تنتقل إلى:</p>
            <div v-for="s in removed" :key="s.id" class="mb-1 flex items-center gap-2">
                <span class="w-32 truncate">{{ s.name }}</span> ←
                <select v-model="moveTo[s.id]" class="field-input w-48 py-1 text-sm"><option :value="undefined">حالة من نفس المجموعة</option><option v-for="t in targets" :key="t.key" :value="t.key">{{ t.name }}</option></select>
            </div>
        </div>
        <label v-if="withDefault" class="mt-4 flex items-center gap-2 text-sm"><input v-model="makeDefault" type="checkbox" class="rounded" /> القالب الافتراضي للقوائم الجديدة</label>
        <p v-for="(e, k) in form.errors" :key="k" class="field-error">{{ e }}</p>
        <template #footer>
            <Btn variant="secondary" @click="open = false">إلغاء</Btn>
            <Btn :loading="form.processing" @click="save">حفظ</Btn>
        </template>
    </Modal>
</template>

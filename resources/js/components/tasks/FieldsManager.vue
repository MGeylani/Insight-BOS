<script setup>
// Fields that apply here, with add / edit / delete.
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Plus, Pencil, Trash2 } from '@lucide/vue';
import Modal from '@/components/Modal.vue';
import Btn from '@/components/Btn.vue';
import FieldDefModal from './FieldDefModal.vue';
import { fieldTypeLabels } from '@/lib/tasks';

const open = defineModel({ type: Boolean, default: false });
const props = defineProps({ fields: Array, types: Array, location: Object });
const editing = ref(null);
const editor = ref(false);
const scopeLabel = { all: 'كل المهام', client: 'العميل', project: 'المشروع', list: 'القائمة' };
const scopes = computed(() => {
    const l = props.location;
    const out = [];
    if (l?.type === 'list') out.push({ type: 'list', id: l.id, label: `هذه القائمة: ${l.name}` });
    if (l?.type === 'project') out.push({ type: 'project', id: l.id, label: `هذا المشروع: ${l.name}` });
    if (l?.type === 'list' && l.project) out.push({ type: 'project', id: l.project.id, label: `المشروع: ${l.project.name}` });
    if (l?.type === 'client') out.push({ type: 'client', id: l.id, label: `هذا العميل: ${l.name}` });
    if (l?.client && l.type !== 'client') out.push({ type: 'client', id: l.client.id, label: `العميل: ${l.client.name}` });
    out.push({ type: 'all', id: null, label: 'كل المهام' });
    return out;
});
const numeric = computed(() => props.fields.filter((f) => ['number', 'money', 'percent', 'rating', 'checkbox'].includes(f.type)));
const add = () => { editing.value = null; editor.value = true; };
const edit = (f) => { editing.value = f; editor.value = true; };
const remove = (f) => confirm(`حذف الحقل «${f.name}»؟ تختفي قيمه من المهام.`) && router.delete(`/tasks/fields/${f.id}`, { preserveScroll: true });
</script>

<template>
    <Modal v-model="open" title="الحقول المخصصة هنا" wide>
        <ul class="divide-y divide-slate-100">
            <li v-for="f in fields" :key="f.id" class="flex items-center gap-3 py-2 text-sm">
                <span class="font-medium text-slate-800">{{ f.name }}</span>
                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">{{ fieldTypeLabels[f.type] }}</span>
                <span class="text-xs text-slate-500">{{ scopeLabel[f.scope_type] }}<template v-if="f.type_ids.length"> · {{ types.filter((t) => f.type_ids.includes(t.id)).map((t) => t.name).join('، ') }}</template></span>
                <button type="button" class="ms-auto rounded p-1 text-slate-400 hover:text-brand" @click="edit(f)"><Pencil class="size-4" /></button>
                <button type="button" class="rounded p-1 text-slate-400 hover:text-red-600" @click="remove(f)"><Trash2 class="size-4" /></button>
            </li>
        </ul>
        <p v-if="!fields.length" class="text-sm text-slate-500">لا حقول بعد. أضف مثلاً «المنصة» (قائمة منسدلة) أو «جولات التعديل» (رقم).</p>
        <template #footer><Btn variant="secondary" @click="open = false">إغلاق</Btn><Btn @click="add"><Plus class="size-4" /> حقل</Btn></template>
        <FieldDefModal v-model="editor" :field="editing" :types="types" :scopes="scopes" :numeric-fields="numeric" />
    </Modal>
</template>

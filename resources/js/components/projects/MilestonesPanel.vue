<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Plus, Pencil, Trash2, CheckCircle2, Circle, ChevronUp, ChevronDown, Flag } from '@lucide/vue';
import Btn from '@/components/Btn.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import { formatDate } from '@/lib/format';

const props = defineProps({ projectId: Number, milestones: Array, canEdit: Boolean });
const base = `/projects/${props.projectId}/milestones`;
const keep = { preserveScroll: true, preserveState: true };

const open = ref(false);
const editId = ref(null);
const form = useForm({ title: '', details: '', due_date: '' });
const add = () => { editId.value = null; form.reset(); form.clearErrors(); open.value = true; };
const edit = (m) => {
    editId.value = m.id;
    form.title = m.title; form.details = m.details ?? ''; form.due_date = m.due_date ?? '';
    form.clearErrors(); open.value = true;
};
const save = () => {
    const opts = { ...keep, onSuccess: () => (open.value = false) };
    editId.value ? form.put(`${base}/${editId.value}`, opts) : form.post(base, opts);
};
const toggle = (m) => router.post(`${base}/${m.id}/toggle`, { done: m.status !== 'done' }, keep);
const move = (m, dir) => router.post(`${base}/${m.id}/move`, { dir }, keep);
const destroy = (m) => confirm(`حذف المرحلة «${m.title}»؟`) && router.delete(`${base}/${m.id}`, keep);
const tone = { pending: 'slate', overdue: 'amber', done: 'green' };
const label = { pending: 'قيد العمل', overdue: 'متأخرة', done: 'منجزة' };
</script>

<template>
    <div class="card overflow-hidden">
        <div class="flex items-center gap-2 border-b border-slate-100 px-5 py-3.5">
            <Flag class="size-4 text-plum" /><h3 class="me-auto font-semibold text-navy">المراحل</h3>
            <Btn v-if="canEdit" size="sm" variant="secondary" @click="add"><Plus class="size-4" /> مرحلة</Btn>
        </div>
        <ul v-if="milestones.length" class="divide-y divide-slate-100">
            <li v-for="(m, i) in milestones" :key="m.id" class="group flex items-start gap-3 px-5 py-3">
                <button type="button" class="mt-0.5 disabled:cursor-default" :disabled="!canEdit" :title="m.status === 'done' ? 'إعادة فتح' : 'تسجيل الإنجاز'" @click="toggle(m)">
                    <CheckCircle2 v-if="m.status === 'done'" class="size-5 text-emerald-600" />
                    <Circle v-else class="size-5 text-slate-300 hover:text-brand" />
                </button>
                <div class="min-w-0 flex-1">
                    <p class="font-medium" :class="m.status === 'done' ? 'text-slate-500 line-through' : 'text-slate-800'">{{ m.title }}</p>
                    <p v-if="m.details" class="whitespace-pre-line text-xs text-slate-500">{{ m.details }}</p>
                    <p v-if="m.done_at" class="text-xs text-emerald-700">أُنجزت {{ formatDate(m.done_at) }}<template v-if="m.done_by"> · {{ m.done_by }}</template></p>
                </div>
                <div class="text-end text-xs">
                    <p class="text-slate-600">{{ formatDate(m.due_date) }}</p>
                    <Badge :tone="tone[m.status]">{{ label[m.status] }}</Badge>
                </div>
                <div v-if="canEdit" class="flex shrink-0 items-center opacity-0 transition group-hover:opacity-100">
                    <button type="button" class="rounded p-1 text-slate-400 hover:bg-slate-100 disabled:opacity-30" :disabled="i === 0" title="لأعلى" @click="move(m, 'up')"><ChevronUp class="size-4" /></button>
                    <button type="button" class="rounded p-1 text-slate-400 hover:bg-slate-100 disabled:opacity-30" :disabled="i === milestones.length - 1" title="لأسفل" @click="move(m, 'down')"><ChevronDown class="size-4" /></button>
                    <button type="button" class="rounded p-1 text-slate-400 hover:bg-slate-100" title="تعديل" @click="edit(m)"><Pencil class="size-4" /></button>
                    <button type="button" class="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600" title="حذف" @click="destroy(m)"><Trash2 class="size-4" /></button>
                </div>
            </li>
        </ul>
        <p v-else class="px-5 py-8 text-center text-sm text-slate-500">لا توجد مراحل. أضفها يدوياً، أو أنشئ المشروع من قالب.</p>

        <Modal v-model="open" :title="editId ? 'تعديل المرحلة' : 'مرحلة جديدة'">
            <div class="space-y-4">
                <Field label="العنوان" id="ms-title" :error="form.errors.title" required><input id="ms-title" v-model="form.title" class="field-input" /></Field>
                <Field label="الموعد" id="ms-due" :error="form.errors.due_date"><input id="ms-due" v-model="form.due_date" type="date" dir="ltr" class="field-input" /></Field>
                <Field label="تفاصيل" id="ms-details" :error="form.errors.details"><textarea id="ms-details" v-model="form.details" rows="3" class="field-input" /></Field>
            </div>
            <template #footer>
                <Btn variant="secondary" @click="open = false">إلغاء</Btn>
                <Btn :loading="form.processing" @click="save">حفظ</Btn>
            </template>
        </Modal>
    </div>
</template>

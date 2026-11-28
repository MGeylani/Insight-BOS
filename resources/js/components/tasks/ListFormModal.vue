<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import Btn from '@/components/Btn.vue';

const open = defineModel({ type: Boolean, default: false });
const props = defineProps({ list: Object, clientId: Number, projectId: Number, milestones: { type: Array, default: () => [] }, templates: { type: Array, default: () => [] } });
const form = useForm({ name: '', description: '', project_milestone_id: null, scheme_template_id: null });
watch(open, (v) => {
    if (!v) return;
    form.clearErrors();
    form.name = props.list?.name ?? '';
    form.description = props.list?.description ?? '';
    form.project_milestone_id = props.list?.milestone_id ?? null;
    form.scheme_template_id = props.templates[0]?.id ?? null;
});
function save() {
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    if (props.list) form.transform((d) => ({ name: d.name, description: d.description, project_milestone_id: d.project_milestone_id })).put(`/tasks/lists/${props.list.id}`, opts);
    else form.transform((d) => ({ ...d, client_id: props.clientId, project_id: props.projectId })).post('/tasks/lists', opts);
}
</script>

<template>
    <Modal v-model="open" :title="list ? 'تعديل القائمة' : 'قائمة مهام جديدة'">
        <div class="space-y-4">
            <Field label="الاسم" id="tl-n" :error="form.errors.name" required hint="مرحلة أو حملة أو شهر، مثل: المطبوعات، حملة رمضان، ديسمبر 2026">
                <input id="tl-n" v-model="form.name" class="field-input" @keydown.enter="save" />
            </Field>
            <Field v-if="milestones.length" label="المرحلة المرتبطة" id="tl-m" :error="form.errors.project_milestone_id" hint="مهام القائمة تحسب تقدّم هذه المرحلة">
                <select id="tl-m" v-model="form.project_milestone_id" class="field-input"><option :value="null">—</option><option v-for="m in milestones" :key="m.id" :value="m.id">{{ m.title }}</option></select>
            </Field>
            <Field v-if="!list && templates.length > 1" label="قالب الحالات" id="tl-t">
                <select id="tl-t" v-model="form.scheme_template_id" class="field-input"><option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }}</option></select>
            </Field>
            <Field label="وصف" id="tl-d" :error="form.errors.description"><textarea id="tl-d" v-model="form.description" rows="2" class="field-input" /></Field>
        </div>
        <template #footer>
            <Btn variant="secondary" @click="open = false">إلغاء</Btn>
            <Btn :loading="form.processing" @click="save">حفظ</Btn>
        </template>
    </Modal>
</template>

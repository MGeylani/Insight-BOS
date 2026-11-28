<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import Btn from '@/components/Btn.vue';

const open = defineModel({ type: Boolean, default: false });
const props = defineProps({ tree: Array, location: Object });
const form = useForm({ title: '', task_list_id: null, open: true });

// Lists the new task can go to, narrowed to the current client / project when there is one.
const options = computed(() => {
    const out = [];
    for (const c of props.tree) {
        if (props.location?.type === 'client' && props.location.id !== c.id) continue;
        for (const l of c.lists) out.push({ id: l.id, label: `${c.name} ← ${l.name}` });
        for (const p of c.projects) {
            if (props.location?.type === 'project' && props.location.id !== p.id) continue;
            for (const l of p.lists) out.push({ id: l.id, label: `${c.name} ← ${p.name} ← ${l.name}` });
        }
    }
    return out;
});
watch(open, (v) => {
    if (!v) return;
    form.reset();
    form.clearErrors();
    form.task_list_id = props.location?.type === 'list' ? props.location.id : options.value[0]?.id ?? null;
});
const save = () => form.post('/tasks', { onSuccess: () => (open.value = false) });
</script>

<template>
    <Modal v-model="open" title="مهمة جديدة">
        <div class="space-y-4">
            <Field label="العنوان" id="nt-t" :error="form.errors.title" required><input id="nt-t" v-model="form.title" class="field-input" @keydown.enter="save" /></Field>
            <Field label="القائمة" id="nt-l" :error="form.errors.task_list_id" :hint="options.length ? '' : 'لا توجد قائمة بعد؛ تُنشأ «مهام عامة» لـ Insight Media تلقائياً.'">
                <select id="nt-l" v-model="form.task_list_id" class="field-input"><option v-for="o in options" :key="o.id" :value="o.id">{{ o.label }}</option></select>
            </Field>
        </div>
        <template #footer>
            <Btn variant="secondary" @click="open = false">إلغاء</Btn>
            <Btn :loading="form.processing" @click="save">إنشاء وفتح</Btn>
        </template>
    </Modal>
</template>

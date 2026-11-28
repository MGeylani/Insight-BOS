<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import Btn from '@/components/Btn.vue';

const open = defineModel({ type: Boolean, default: false });
const props = defineProps({ location: Object, view: Object, filters: Object });
const form = useForm({ name: '', is_shared: false, scope: 'here' });
watch(open, (v) => { if (v) { form.reset(); form.clearErrors(); form.scope = props.location ? 'here' : 'all'; } });
function save() {
    form.transform((d) => ({
        name: d.name, is_shared: d.is_shared, type: props.view.view,
        location_type: d.scope === 'here' ? props.location?.type ?? null : null,
        location_id: d.scope === 'here' ? props.location?.id ?? null : null,
        config: { ...props.view, filters: props.filters },
    })).post('/tasks/views', { onSuccess: () => (open.value = false) });
}
</script>

<template>
    <Modal v-model="open" title="حفظ العرض">
        <div class="space-y-4">
            <Field label="الاسم" id="sv-n" :error="form.errors.name" required hint="مثل: تقويم النشر، مهامي المتأخرة، مراجعات العميل">
                <input id="sv-n" v-model="form.name" class="field-input" @keydown.enter="save" />
            </Field>
            <Field v-if="location" label="أين يظهر">
                <select v-model="form.scope" class="field-input"><option value="here">هنا فقط: {{ location.name }}</option><option value="all">في كل المهام</option></select>
            </Field>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_shared" type="checkbox" class="rounded" /> مشترك مع الفريق (وإلا فهو خاص بك)</label>
            <p class="text-xs text-slate-500">يحفظ نوع العرض والفلاتر والتجميع والترتيب والأعمدة الحالية.</p>
        </div>
        <template #footer><Btn variant="secondary" @click="open = false">إلغاء</Btn><Btn :loading="form.processing" @click="save">حفظ</Btn></template>
    </Modal>
</template>

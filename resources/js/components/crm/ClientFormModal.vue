<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import Btn from '@/components/Btn.vue';

const open = defineModel({ type: Boolean, default: false });
const props = defineProps({ client: Object, options: Object });

const blank = () => ({
    name: '', legal_name: '', type: 'company', industry: '', email: '', phone: '', website: '', country: '', city: '',
    address: '', tax_number: '', currency: 'USD', source: '', owner_id: '', notes: '',
});
const form = useForm(blank());
watch(open, (v) => {
    if (!v) return;
    form.clearErrors();
    const src = props.client ?? blank();
    Object.keys(blank()).forEach((k) => (form[k] = src[k] ?? blank()[k]));
});

function save() {
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    form.transform((d) => ({ ...d, owner_id: d.owner_id || null, source: d.source || null }));
    props.client ? form.put(`/crm/clients/${props.client.id}`, opts) : form.post('/crm/clients', opts);
}
</script>

<template>
    <Modal v-model="open" :title="client ? 'تعديل بيانات العميل' : 'عميل جديد'" wide>
        <div class="grid gap-4 sm:grid-cols-3">
            <Field label="اسم العميل" id="cl-n" :error="form.errors.name" required class="sm:col-span-2"><input id="cl-n" v-model="form.name" class="field-input" /></Field>
            <Field label="النوع" id="cl-t"><select id="cl-t" v-model="form.type" class="field-input"><option v-for="(l, k) in options.clientTypes" :key="k" :value="k">{{ l }}</option></select></Field>
            <Field label="الاسم القانوني (للعقود والفواتير)" id="cl-l" :error="form.errors.legal_name" class="sm:col-span-2"><input id="cl-l" v-model="form.legal_name" class="field-input" /></Field>
            <Field label="القطاع" id="cl-i"><input id="cl-i" v-model="form.industry" class="field-input" /></Field>
            <Field label="الهاتف" id="cl-p" :error="form.errors.phone"><input id="cl-p" v-model="form.phone" dir="ltr" class="field-input text-start" /></Field>
            <Field label="البريد" id="cl-e" :error="form.errors.email"><input id="cl-e" v-model="form.email" type="email" dir="ltr" class="field-input text-start" /></Field>
            <Field label="الموقع الإلكتروني" id="cl-w"><input id="cl-w" v-model="form.website" dir="ltr" class="field-input text-start" /></Field>
            <Field label="الدولة" id="cl-c"><input id="cl-c" v-model="form.country" class="field-input" /></Field>
            <Field label="المدينة" id="cl-ci"><input id="cl-ci" v-model="form.city" class="field-input" /></Field>
            <Field label="الرقم الضريبي" id="cl-tx"><input id="cl-tx" v-model="form.tax_number" dir="ltr" class="field-input text-start" /></Field>
            <Field label="العنوان" id="cl-a" class="sm:col-span-3"><input id="cl-a" v-model="form.address" class="field-input" /></Field>
            <Field label="عملة التعامل" id="cl-cur"><select id="cl-cur" v-model="form.currency" class="field-input"><option v-for="c in options.currencies" :key="c">{{ c }}</option></select></Field>
            <Field label="المصدر" id="cl-s"><select id="cl-s" v-model="form.source" class="field-input"><option value="">—</option><option v-for="(l, k) in options.sources" :key="k" :value="k">{{ l }}</option></select></Field>
            <Field label="مسؤول الحساب" id="cl-o"><select id="cl-o" v-model="form.owner_id" class="field-input"><option value="">أنا</option><option v-for="u in options.users" :key="u.id" :value="u.id">{{ u.name }}</option></select></Field>
            <Field label="ملاحظات" id="cl-no" class="sm:col-span-3"><textarea id="cl-no" v-model="form.notes" rows="3" class="field-input" /></Field>
        </div>
        <template #footer>
            <Btn variant="secondary" @click="open = false">إلغاء</Btn>
            <Btn :loading="form.processing" @click="save">حفظ</Btn>
        </template>
    </Modal>
</template>

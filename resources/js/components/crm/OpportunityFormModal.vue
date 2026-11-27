<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import Btn from '@/components/Btn.vue';

const open = defineModel({ type: Boolean, default: false });
const props = defineProps({ opportunity: Object, options: Object, clients: Array, contacts: Array, clientId: Number });

const blank = () => ({ client_id: props.clientId ?? '', contact_id: '', title: '', amount: '', currency: 'USD', expected_close_date: '', owner_id: '', notes: '', stage: 'discovery' });
const form = useForm(blank());
watch(open, (v) => {
    if (!v) return;
    form.clearErrors();
    const src = props.opportunity ?? blank();
    Object.keys(blank()).forEach((k) => (form[k] = src[k] ?? blank()[k]));
});
const openStages = computed(() => props.options.oppStages.filter((s) => !['won', 'lost'].includes(s.key)));

function save() {
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    form.transform((d) => ({ ...d, owner_id: d.owner_id || null, contact_id: d.contact_id || null, expected_close_date: d.expected_close_date || null }));
    props.opportunity ? form.put(`/crm/opportunities/${props.opportunity.id}`, opts) : form.post('/crm/opportunities', opts);
}
</script>

<template>
    <Modal v-model="open" :title="opportunity ? 'تعديل الفرصة' : 'فرصة جديدة'">
        <div class="space-y-4">
            <Field v-if="!opportunity && !clientId" label="العميل" id="op-c" :error="form.errors.client_id" required>
                <select id="op-c" v-model="form.client_id" class="field-input"><option value="">اختر…</option><option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option></select>
            </Field>
            <Field label="عنوان الفرصة" id="op-t" :error="form.errors.title" required><input id="op-t" v-model="form.title" class="field-input" placeholder="مثال: إدارة حسابات التواصل 6 أشهر" /></Field>
            <div class="grid gap-4 sm:grid-cols-2">
                <Field label="القيمة" id="op-a" :error="form.errors.amount" required>
                    <div class="flex gap-2">
                        <input id="op-a" v-model="form.amount" type="number" min="0" step="0.01" dir="ltr" class="field-input text-start" />
                        <select v-model="form.currency" class="field-input w-24"><option v-for="c in options.currencies" :key="c">{{ c }}</option></select>
                    </div>
                </Field>
                <Field label="تاريخ الإغلاق المتوقع" id="op-d" :error="form.errors.expected_close_date"><input id="op-d" v-model="form.expected_close_date" type="date" dir="ltr" class="field-input" /></Field>
                <Field v-if="!opportunity" label="المرحلة" id="op-s"><select id="op-s" v-model="form.stage" class="field-input"><option v-for="s in openStages" :key="s.key" :value="s.key">{{ s.label }} ({{ s.probability }}%)</option></select></Field>
                <Field v-if="contacts?.length" label="جهة الاتصال" id="op-ct"><select id="op-ct" v-model="form.contact_id" class="field-input"><option value="">—</option><option v-for="c in contacts" :key="c.id" :value="c.id">{{ c.name }}</option></select></Field>
                <Field label="المسؤول" id="op-o"><select id="op-o" v-model="form.owner_id" class="field-input"><option value="">أنا</option><option v-for="u in options.users" :key="u.id" :value="u.id">{{ u.name }}</option></select></Field>
            </div>
            <Field label="ملاحظات" id="op-n"><textarea id="op-n" v-model="form.notes" rows="3" class="field-input" /></Field>
        </div>
        <template #footer>
            <Btn variant="secondary" @click="open = false">إلغاء</Btn>
            <Btn :loading="form.processing" @click="save">حفظ</Btn>
        </template>
    </Modal>
</template>

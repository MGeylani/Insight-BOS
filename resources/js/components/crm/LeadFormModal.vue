<script setup>
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import Btn from '@/components/Btn.vue';
import DuplicateList from '@/components/crm/DuplicateList.vue';

const open = defineModel({ type: Boolean, default: false });
const props = defineProps({ lead: Object, options: Object, defaultStage: String });

const blank = () => ({
    name: '', company_name: '', job_title: '', email: '', phone: '', source: '', stage: props.defaultStage || 'new',
    rating: 'warm', expected_value: '', currency: 'USD', interest: '', owner_id: '', notes: '',
});
const form = useForm(blank());
const dupes = ref([]);

watch(open, (v) => {
    if (!v) return;
    form.clearErrors();
    const src = props.lead ?? blank();
    Object.keys(blank()).forEach((k) => (form[k] = src[k] ?? ''));
    if (!props.lead) form.stage = props.defaultStage || 'new';
    dupes.value = [];
});

let timer;
watch(() => [form.email, form.phone, form.company_name], () => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        if (!form.email && !form.phone && !form.company_name) return (dupes.value = []);
        const q = new URLSearchParams({ email: form.email || '', phone: form.phone || '', company_name: form.company_name || '' });
        if (props.lead) q.set('ignore', props.lead.id);
        try {
            const r = await fetch(`/crm/leads/duplicates?${q}`, { headers: { Accept: 'application/json' } });
            dupes.value = r.ok ? await r.json() : [];
        } catch { dupes.value = []; }
    }, 400);
});

function save() {
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    const data = (d) => ({ ...d, owner_id: d.owner_id || null, expected_value: d.expected_value === '' ? null : d.expected_value });
    props.lead ? form.transform(data).put(`/crm/leads/${props.lead.id}`, opts) : form.transform(data).post('/crm/leads', opts);
}
</script>

<template>
    <Modal v-model="open" :title="lead ? 'تعديل العميل المحتمل' : 'عميل محتمل جديد'" wide>
        <div class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-3">
                <Field label="اسم الشخص" id="l-name" :error="form.errors.name" required><input id="l-name" v-model="form.name" class="field-input" /></Field>
                <Field label="الشركة / الجهة" id="l-co" :error="form.errors.company_name"><input id="l-co" v-model="form.company_name" class="field-input" /></Field>
                <Field label="المسمى الوظيفي" id="l-jt" :error="form.errors.job_title"><input id="l-jt" v-model="form.job_title" class="field-input" /></Field>
                <Field label="الهاتف / واتساب" id="l-ph" :error="form.errors.phone"><input id="l-ph" v-model="form.phone" dir="ltr" class="field-input text-start" /></Field>
                <Field label="البريد" id="l-em" :error="form.errors.email"><input id="l-em" v-model="form.email" type="email" dir="ltr" class="field-input text-start" /></Field>
                <Field label="المصدر" id="l-src" :error="form.errors.source">
                    <select id="l-src" v-model="form.source" class="field-input"><option value="">—</option><option v-for="(l, k) in options.sources" :key="k" :value="k">{{ l }}</option></select>
                </Field>
            </div>
            <DuplicateList :items="dupes" title="انتبه: قد يكون هذا السجل موجوداً" />
            <div class="grid gap-4 sm:grid-cols-4">
                <Field label="المرحلة" id="l-st">
                    <select id="l-st" v-model="form.stage" class="field-input"><option v-for="(l, k) in options.leadStages" :key="k" :value="k">{{ l }}</option></select>
                </Field>
                <Field label="التقييم" id="l-rt">
                    <select id="l-rt" v-model="form.rating" class="field-input"><option value="">—</option><option v-for="(l, k) in options.ratings" :key="k" :value="k">{{ l }}</option></select>
                </Field>
                <Field label="القيمة المتوقعة" id="l-ev" :error="form.errors.expected_value">
                    <div class="flex gap-2">
                        <input id="l-ev" v-model="form.expected_value" type="number" min="0" step="0.01" dir="ltr" class="field-input text-start" />
                        <select v-model="form.currency" class="field-input w-24"><option v-for="c in options.currencies" :key="c">{{ c }}</option></select>
                    </div>
                </Field>
                <Field label="المسؤول" id="l-ow">
                    <select id="l-ow" v-model="form.owner_id" class="field-input"><option value="">أنا</option><option v-for="u in options.users" :key="u.id" :value="u.id">{{ u.name }}</option></select>
                </Field>
            </div>
            <Field label="ما الذي يهمه؟" id="l-in" hint="الخدمة أو المشروع الذي سأل عنه" :error="form.errors.interest"><input id="l-in" v-model="form.interest" class="field-input" /></Field>
            <Field label="ملاحظات" id="l-no" :error="form.errors.notes"><textarea id="l-no" v-model="form.notes" rows="3" class="field-input" /></Field>
        </div>
        <template #footer>
            <Btn variant="secondary" @click="open = false">إلغاء</Btn>
            <Btn :loading="form.processing" @click="save">حفظ</Btn>
        </template>
    </Modal>
</template>

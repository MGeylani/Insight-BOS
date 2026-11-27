<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Field from '@/components/Field.vue';

const props = defineProps({ company: Object, quotes: Object, contracts: Object });
const form = useForm({ ...props.company });
const qForm = useForm({ ...props.quotes });
const cForm = useForm({ ...props.contracts });
const fields = [
    { key: 'name', label: 'الاسم التجاري', required: true },
    { key: 'legal_name', label: 'الاسم القانوني' },
    { key: 'email', label: 'البريد الإلكتروني', ltr: true },
    { key: 'phone', label: 'الهاتف', ltr: true },
    { key: 'website', label: 'الموقع الإلكتروني', ltr: true },
    { key: 'tax_number', label: 'الرقم الضريبي', ltr: true },
];
</script>

<template>
    <AppLayout title="بيانات الشركة">
        <PageHeader title="بيانات الشركة" subtitle="تظهر في الفواتير وعروض الأسعار والتقارير." />
        <form class="card grid gap-5 p-6 md:grid-cols-2" @submit.prevent="form.put('/settings/company', { preserveScroll: true })">
            <Field v-for="f in fields" :key="f.key" :label="f.label" :id="f.key" :error="form.errors[f.key]" :required="f.required">
                <input :id="f.key" v-model="form[f.key]" :dir="f.ltr ? 'ltr' : null" class="field-input" :class="{ 'text-start': f.ltr }" />
            </Field>
            <Field label="العنوان" id="address" :error="form.errors.address" class="md:col-span-2">
                <textarea id="address" v-model="form.address" rows="2" class="field-input" />
            </Field>
            <div class="md:col-span-2"><Btn type="submit" :loading="form.processing">حفظ</Btn></div>
        </form>

        <h2 class="mb-3 mt-8 text-lg font-semibold text-navy">عروض الأسعار</h2>
        <form class="card grid gap-5 p-6 md:grid-cols-4" @submit.prevent="qForm.put('/settings/quote-defaults', { preserveScroll: true })">
            <Field label="مدة صلاحية العرض (يوم)" id="q-days" :error="qForm.errors.validity_days" required>
                <input id="q-days" v-model="qForm.validity_days" type="number" min="1" max="365" dir="ltr" class="field-input text-start" />
            </Field>
            <Field label="الشروط الافتراضية" id="q-terms" :error="qForm.errors.default_terms" hint="تُنسخ إلى كل عرض جديد ويمكن تعديلها فيه." class="md:col-span-4">
                <textarea id="q-terms" v-model="qForm.default_terms" rows="5" class="field-input" placeholder="مثال: دفعة مقدمة 50% عند الموافقة، والباقي عند التسليم." />
            </Field>
            <div class="md:col-span-4"><Btn type="submit" :loading="qForm.processing">حفظ</Btn></div>
        </form>

        <h2 class="mb-3 mt-8 text-lg font-semibold text-navy">العقود</h2>
        <form class="card grid gap-5 p-6 md:grid-cols-2" @submit.prevent="cForm.put('/settings/contract-defaults', { preserveScroll: true })">
            <Field label="الشروط الافتراضية لعقود المشاريع" id="c-project" :error="cForm.errors.terms_project" hint="تُنسخ إلى كل عقد جديد من هذا النوع ويمكن تعديلها فيه.">
                <textarea id="c-project" v-model="cForm.terms_project" rows="8" class="field-input" placeholder="مثال: يلتزم الطرف الثاني بتزويد الطرف الأول بالمواد اللازمة خلال 5 أيام عمل…" />
            </Field>
            <Field label="الشروط الافتراضية للعقود الشهرية" id="c-retainer" :error="cForm.errors.terms_retainer">
                <textarea id="c-retainer" v-model="cForm.terms_retainer" rows="8" class="field-input" placeholder="مثال: تشمل الرسوم عدد المنشورات المتفق عليه شهرياً، وما زاد يُحتسب بسعر الكتالوج…" />
            </Field>
            <div class="md:col-span-2"><Btn type="submit" :loading="cForm.processing">حفظ</Btn></div>
        </form>
    </AppLayout>
</template>

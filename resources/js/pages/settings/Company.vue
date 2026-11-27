<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Field from '@/components/Field.vue';

const props = defineProps({ company: Object });
const form = useForm({ ...props.company });
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
    </AppLayout>
</template>

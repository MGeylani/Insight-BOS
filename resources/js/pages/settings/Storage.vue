<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Cloud, CircleCheck, Info } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Field from '@/components/Field.vue';
import Badge from '@/components/Badge.vue';

const props = defineProps({ providers: Object, connection: Object });

const form = useForm({
    provider: props.connection?.provider ?? 'google_drive',
    max_upload_mb: props.connection?.max_upload_mb ?? 100,
    allowed_types: props.connection?.allowed_types ?? [],
});
const typesText = computed({
    get: () => form.allowed_types.join(', '),
    set: (v) => (form.allowed_types = v.split(/[\s,،]+/).map((x) => x.trim().toLowerCase().replace(/^\./, '')).filter(Boolean)),
});
const statusTone = { connected: 'green', disconnected: 'amber', error: 'red' };
const statusLabel = { connected: 'متصل', disconnected: 'غير متصل بعد', error: 'خطأ في الاتصال' };
</script>

<template>
    <AppLayout title="التخزين السحابي">
        <PageHeader title="التخزين السحابي" subtitle="مزود واحد نشط للنظام كله. الملفات تُرفع من داخل النظام إلى الخدمة المختارة، في مجلد لكل عميل ومشروع، ولا تبقى على خادم الموقع." />

        <div v-if="connection" class="card mb-6 flex flex-wrap items-center gap-4 p-5">
            <span class="flex size-11 items-center justify-center rounded-xl bg-navy-50 text-navy"><Cloud class="size-5" /></span>
            <div class="flex-1">
                <p class="font-semibold text-slate-800">{{ providers[connection.provider]?.label }}</p>
                <p class="text-sm text-slate-500">{{ connection.account_label || 'لم يُربط حساب بعد' }}</p>
            </div>
            <Badge :tone="statusTone[connection.status]">{{ statusLabel[connection.status] }}</Badge>
        </div>

        <form class="space-y-6" @submit.prevent="form.put('/settings/storage', { preserveScroll: true })">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <label
                    v-for="(p, key) in providers"
                    :key="key"
                    class="card relative cursor-pointer p-5 transition"
                    :class="form.provider === key ? 'border-brand ring-4 ring-brand/15' : 'hover:border-slate-300'"
                >
                    <input v-model="form.provider" type="radio" :value="key" class="sr-only" />
                    <CircleCheck v-if="form.provider === key" class="absolute top-4 end-4 size-5 text-brand" />
                    <p class="font-semibold text-slate-800">{{ p.label }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ p.auth === 'oauth' ? 'ربط بتسجيل دخول لمرة واحدة' : 'ربط بعنوان الجهاز واسم مستخدم وكلمة مرور' }}</p>
                    <p class="mt-3 text-xs text-slate-500">{{ p.note }}</p>
                </label>
            </div>

            <div class="card grid gap-5 p-6 md:grid-cols-2">
                <Field label="الحد الأقصى لحجم الملف (ميغابايت)" id="max" :error="form.errors.max_upload_mb">
                    <input id="max" v-model.number="form.max_upload_mb" type="number" min="1" max="2048" dir="ltr" class="field-input text-start" />
                </Field>
                <Field label="الأنواع المسموحة" id="types" :error="form.errors.allowed_types" hint="مثال: pdf, jpg, png, mp4, ai, psd — اتركه فارغاً للسماح بكل الأنواع.">
                    <input id="types" v-model="typesText" dir="ltr" class="field-input text-start" />
                </Field>
            </div>

            <div class="flex items-start gap-2 rounded-xl bg-sky/10 px-4 py-3 text-sm text-navy">
                <Info class="mt-0.5 size-4 shrink-0" />
                <p>زر «ربط الحساب» لكل خدمة يُفعّل مع أول موديول يستخدم المرفقات (العملاء والمشاريع في المرحلة 2). اختر المزود الآن ليُجهّز النظام عليه.</p>
            </div>

            <Btn type="submit" :loading="form.processing">حفظ الإعدادات</Btn>
        </form>
    </AppLayout>
</template>

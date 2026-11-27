<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/layouts/AuthLayout.vue';
import Btn from '@/components/Btn.vue';
import Field from '@/components/Field.vue';

defineProps({ status: String });
const form = useForm({ email: '' });
</script>

<template>
    <AuthLayout title="استعادة كلمة المرور" subtitle="أدخل بريدك وسنرسل لك رابطاً لتعيين كلمة مرور جديدة.">
        <div v-if="status" class="mb-5 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ status }}</div>
        <form class="space-y-5" @submit.prevent="form.post('/forgot-password')">
            <Field label="البريد الإلكتروني" id="email" :error="form.errors.email">
                <input id="email" v-model="form.email" type="email" dir="ltr" required autofocus class="field-input text-start" />
            </Field>
            <Btn type="submit" class="w-full" :loading="form.processing">إرسال الرابط</Btn>
            <p class="text-center text-sm"><Link href="/login" class="text-brand hover:underline">العودة لتسجيل الدخول</Link></p>
        </form>
    </AuthLayout>
</template>

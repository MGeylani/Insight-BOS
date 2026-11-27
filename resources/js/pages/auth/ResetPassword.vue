<script setup>
import { useForm } from '@inertiajs/vue3';
import AuthLayout from '@/layouts/AuthLayout.vue';
import Btn from '@/components/Btn.vue';
import Field from '@/components/Field.vue';

const props = defineProps({ token: String, email: String });
const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' });
</script>

<template>
    <AuthLayout title="تعيين كلمة مرور جديدة" subtitle="10 أحرف على الأقل، فيها حروف وأرقام.">
        <form class="space-y-5" @submit.prevent="form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') })">
            <Field label="البريد الإلكتروني" id="email" :error="form.errors.email">
                <input id="email" v-model="form.email" type="email" dir="ltr" required class="field-input text-start" />
            </Field>
            <Field label="كلمة المرور الجديدة" id="password" :error="form.errors.password">
                <input id="password" v-model="form.password" type="password" dir="ltr" autocomplete="new-password" required class="field-input text-start" />
            </Field>
            <Field label="تأكيد كلمة المرور" id="password_confirmation">
                <input id="password_confirmation" v-model="form.password_confirmation" type="password" dir="ltr" autocomplete="new-password" required class="field-input text-start" />
            </Field>
            <Btn type="submit" class="w-full" :loading="form.processing">حفظ كلمة المرور</Btn>
        </form>
    </AuthLayout>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Field from '@/components/Field.vue';

const props = defineProps({ user: Object });
const info = useForm({ name: props.user.name, phone: props.user.phone ?? '' });
const pwd = useForm({ current_password: '', password: '', password_confirmation: '' });
const savePwd = () => pwd.put('/profile/password', { preserveScroll: true, onSuccess: () => pwd.reset() });
</script>

<template>
    <AppLayout title="حسابي">
        <PageHeader title="حسابي" :subtitle="user.email" />
        <div class="grid gap-6 lg:grid-cols-2">
            <form class="card space-y-5 p-6" @submit.prevent="info.patch('/profile', { preserveScroll: true })">
                <h2 class="font-semibold text-slate-800">البيانات الشخصية</h2>
                <Field label="الاسم" id="name" :error="info.errors.name"><input id="name" v-model="info.name" class="field-input" required /></Field>
                <Field label="الهاتف" id="phone" :error="info.errors.phone"><input id="phone" v-model="info.phone" dir="ltr" class="field-input text-start" /></Field>
                <Btn type="submit" :loading="info.processing">حفظ</Btn>
            </form>
            <form class="card space-y-5 p-6" @submit.prevent="savePwd">
                <h2 class="font-semibold text-slate-800">تغيير كلمة المرور</h2>
                <Field label="كلمة المرور الحالية" id="cp" :error="pwd.errors.current_password"><input id="cp" v-model="pwd.current_password" type="password" dir="ltr" class="field-input text-start" autocomplete="current-password" /></Field>
                <Field label="كلمة المرور الجديدة" id="np" :error="pwd.errors.password" hint="10 أحرف على الأقل، فيها حروف وأرقام."><input id="np" v-model="pwd.password" type="password" dir="ltr" class="field-input text-start" autocomplete="new-password" /></Field>
                <Field label="تأكيد كلمة المرور" id="np2"><input id="np2" v-model="pwd.password_confirmation" type="password" dir="ltr" class="field-input text-start" autocomplete="new-password" /></Field>
                <Btn type="submit" :loading="pwd.processing">تغيير كلمة المرور</Btn>
            </form>
        </div>
    </AppLayout>
</template>

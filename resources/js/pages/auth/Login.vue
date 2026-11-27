<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Eye, EyeOff } from '@lucide/vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import Btn from '@/components/Btn.vue';
import Field from '@/components/Field.vue';

defineProps({ status: String });
const form = useForm({ email: '', password: '', remember: true });
const show = ref(false);
const submit = () => form.post('/login', { onFinish: () => form.reset('password') });
</script>

<template>
    <AuthLayout title="تسجيل الدخول" subtitle="أهلاً بك في منظومة Insight Media.">
        <div v-if="status" class="mb-5 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ status }}</div>
        <form class="space-y-5" @submit.prevent="submit">
            <Field label="البريد الإلكتروني" id="email" :error="form.errors.email">
                <input id="email" v-model="form.email" type="email" dir="ltr" autocomplete="username" required autofocus class="field-input text-start" />
            </Field>
            <Field label="كلمة المرور" id="password" :error="form.errors.password">
                <div class="relative">
                    <input id="password" v-model="form.password" :type="show ? 'text' : 'password'" dir="ltr" autocomplete="current-password" required class="field-input pe-11 text-start" />
                    <button type="button" class="absolute inset-y-0 end-0 px-3 text-slate-400 hover:text-slate-600" :aria-label="show ? 'إخفاء' : 'إظهار'" @click="show = !show">
                        <EyeOff v-if="show" class="size-4" /><Eye v-else class="size-4" />
                    </button>
                </div>
            </Field>
            <div class="flex items-center justify-between text-sm">
                <label class="flex items-center gap-2 text-slate-600"><input v-model="form.remember" type="checkbox" class="size-4 rounded border-slate-300 text-navy" /> تذكرني</label>
                <Link href="/forgot-password" class="font-medium text-brand hover:underline">نسيت كلمة المرور؟</Link>
            </div>
            <Btn type="submit" class="w-full" :loading="form.processing">دخول</Btn>
        </form>
    </AuthLayout>
</template>

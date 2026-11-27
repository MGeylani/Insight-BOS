<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Field from '@/components/Field.vue';
import Toggle from '@/components/Toggle.vue';
import PermissionMatrix from '@/components/PermissionMatrix.vue';

const props = defineProps({ user: Object, catalog: Array, scopes: Object, templates: Array });
const editing = computed(() => !!props.user);

const form = useForm({
    name: props.user?.name ?? '',
    email: props.user?.email ?? '',
    phone: props.user?.phone ?? '',
    password: '',
    password_confirmation: '',
    is_active: props.user?.is_active ?? true,
    role_template_id: props.user?.role_template_id ?? null,
    permissions: { ...(props.user?.permissions ?? {}) },
});

const chosen = ref(form.role_template_id ?? '');
function applyTemplate() {
    const t = props.templates.find((x) => x.id === Number(chosen.value));
    if (!t) return;
    const hasGrants = Object.keys(form.permissions).length > 0;
    if (hasGrants && !confirm(`استبدال الصلاحيات الحالية بقالب «${t.name}»؟ يمكنك تعديلها بعد ذلك.`)) return;
    form.permissions = { ...t.permissions };
    form.role_template_id = t.id;
}

const submit = () => {
    if (editing.value) form.put(`/admin/users/${props.user.id}`);
    else form.post('/admin/users');
};
</script>

<template>
    <AppLayout :title="editing ? 'تعديل مستخدم' : 'مستخدم جديد'">
        <PageHeader :title="editing ? `تعديل: ${user.name}` : 'مستخدم جديد'" subtitle="البيانات الأساسية ثم الصلاحيات لكل موديول.">
            <template #actions>
                <Btn variant="secondary" href="/admin/users">إلغاء</Btn>
                <Btn :loading="form.processing" @click="submit">حفظ</Btn>
            </template>
        </PageHeader>

        <form class="space-y-6" @submit.prevent="submit">
            <div class="card grid gap-5 p-6 md:grid-cols-2">
                <Field label="الاسم الكامل" id="name" :error="form.errors.name" required><input id="name" v-model="form.name" class="field-input" required /></Field>
                <Field label="البريد الإلكتروني (اسم الدخول)" id="email" :error="form.errors.email" required><input id="email" v-model="form.email" type="email" dir="ltr" class="field-input text-start" required /></Field>
                <Field label="الهاتف" id="phone" :error="form.errors.phone"><input id="phone" v-model="form.phone" dir="ltr" class="field-input text-start" /></Field>
                <div class="flex items-end pb-2">
                    <Toggle v-model="form.is_active" label="الحساب نشط" :disabled="user?.is_super_admin" />
                </div>
                <Field :label="editing ? 'كلمة مرور جديدة (اتركها فارغة للإبقاء)' : 'كلمة المرور'" id="password" :error="form.errors.password" :required="!editing" hint="10 أحرف على الأقل، فيها حروف وأرقام.">
                    <input id="password" v-model="form.password" type="password" dir="ltr" autocomplete="new-password" class="field-input text-start" />
                </Field>
                <Field label="تأكيد كلمة المرور" id="password_confirmation">
                    <input id="password_confirmation" v-model="form.password_confirmation" type="password" dir="ltr" autocomplete="new-password" class="field-input text-start" />
                </Field>
            </div>

            <div class="card p-6">
                <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-navy">الصلاحيات</h2>
                        <p class="text-sm text-slate-500">اختر قالباً جاهزاً لتعبئة سريعة، ثم عدّل ما تريد لهذا الشخص تحديداً.</p>
                    </div>
                    <div v-if="!user?.is_super_admin" class="flex items-end gap-2">
                        <select v-model="chosen" class="field-input w-56">
                            <option value="">— اختر قالباً —</option>
                            <option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                        <Btn variant="secondary" :disabled="!chosen" @click="applyTemplate">تطبيق</Btn>
                    </div>
                </div>
                <div v-if="user?.is_super_admin" class="rounded-xl bg-navy-50 px-4 py-3 text-sm text-navy">هذا الحساب مدير النظام ويملك كل الصلاحيات تلقائياً.</div>
                <PermissionMatrix v-else v-model="form.permissions" :catalog="catalog" :scopes="scopes" />
            </div>
        </form>
    </AppLayout>
</template>

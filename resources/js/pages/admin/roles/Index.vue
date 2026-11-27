<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Plus, Pencil, Trash2 } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Field from '@/components/Field.vue';
import Modal from '@/components/Modal.vue';
import PermissionMatrix from '@/components/PermissionMatrix.vue';

const props = defineProps({ templates: Array, catalog: Array, scopes: Object });
const open = ref(false);
const editingId = ref(null);
const form = useForm({ name: '', description: '', permissions: {} });

function edit(t) {
    editingId.value = t?.id ?? null;
    form.clearErrors();
    form.name = t?.name ?? '';
    form.description = t?.description ?? '';
    form.permissions = { ...(t?.permissions ?? {}) };
    open.value = true;
}

function save() {
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    editingId.value ? form.put(`/admin/roles/${editingId.value}`, opts) : form.post('/admin/roles', opts);
}

function remove(t) {
    if (confirm(`حذف القالب «${t.name}»؟ لن تتغير صلاحيات من طُبّق عليهم.`)) router.delete(`/admin/roles/${t.id}`, { preserveScroll: true });
}

const count = (p) => Object.keys(p ?? {}).length;
</script>

<template>
    <AppLayout title="قوالب الصلاحيات">
        <PageHeader title="قوالب الصلاحيات" subtitle="قوالب اختيارية لتسريع إعداد المستخدمين. تعديل القالب لا يغيّر صلاحيات من طُبّق عليهم سابقاً.">
            <template #actions><Btn @click="edit(null)"><Plus class="size-4" /> قالب جديد</Btn></template>
        </PageHeader>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <div v-for="t in templates" :key="t.id" class="card flex flex-col p-5">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="font-semibold text-navy">{{ t.name }}</p>
                        <p class="mt-1 text-sm text-slate-500">{{ t.description || '—' }}</p>
                    </div>
                    <div class="flex gap-1">
                        <button class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-navy" title="تعديل" @click="edit(t)"><Pencil class="size-4" /></button>
                        <button class="rounded-lg p-2 text-slate-500 hover:bg-red-50 hover:text-red-600" title="حذف" @click="remove(t)"><Trash2 class="size-4" /></button>
                    </div>
                </div>
                <div class="mt-auto flex gap-4 pt-4 text-sm text-slate-600">
                    <span><span class="num font-semibold text-navy">{{ count(t.permissions) }}</span> صلاحية</span>
                    <span><span class="num font-semibold text-navy">{{ t.users_count }}</span> مستخدم</span>
                </div>
            </div>
        </div>

        <Modal v-model="open" :title="editingId ? 'تعديل القالب' : 'قالب جديد'" wide>
            <div class="grid gap-4 md:grid-cols-2">
                <Field label="اسم القالب" id="tname" :error="form.errors.name" required><input id="tname" v-model="form.name" class="field-input" /></Field>
                <Field label="وصف مختصر" id="tdesc" :error="form.errors.description"><input id="tdesc" v-model="form.description" class="field-input" /></Field>
            </div>
            <div class="mt-5"><PermissionMatrix v-model="form.permissions" :catalog="catalog" :scopes="scopes" /></div>
            <template #footer>
                <Btn variant="secondary" @click="open = false">إلغاء</Btn>
                <Btn :loading="form.processing" @click="save">حفظ القالب</Btn>
            </template>
        </Modal>
    </AppLayout>
</template>

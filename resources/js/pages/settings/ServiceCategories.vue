<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus, Pencil } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Field from '@/components/Field.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import Toggle from '@/components/Toggle.vue';
import { formatNumber } from '@/lib/format';

defineProps({ categories: Array });
const open = ref(false);
const editingId = ref(null);
const form = useForm({ name: '', tax_rate: 0, is_active: true, sort: 0 });

function edit(c) {
    editingId.value = c?.id ?? null;
    form.clearErrors();
    Object.assign(form, { name: c?.name ?? '', tax_rate: c ? Number(c.tax_rate) : 0, is_active: c?.is_active ?? true, sort: c?.sort ?? 0 });
    open.value = true;
}
function save() {
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    editingId.value ? form.put(`/settings/service-categories/${editingId.value}`, opts) : form.post('/settings/service-categories', opts);
}
</script>

<template>
    <AppLayout title="تصنيفات الخدمات والضريبة">
        <PageHeader title="تصنيفات الخدمات والضريبة" subtitle="لكل تصنيف نسبة ضريبة تُطبّق تلقائياً على بنود عروض الأسعار والفواتير. حالياً 0%، وتُعدّل يدوياً عند الحاجة.">
            <template #actions><Btn @click="edit(null)"><Plus class="size-4" /> تصنيف جديد</Btn></template>
        </PageHeader>
        <div class="card overflow-hidden">
            <table class="tbl">
                <thead><tr><th>التصنيف</th><th>نسبة الضريبة</th><th>الحالة</th><th class="w-px"></th></tr></thead>
                <tbody>
                    <tr v-for="c in categories" :key="c.id" :class="{ 'opacity-50': !c.is_active }">
                        <td class="font-medium">{{ c.name }}</td>
                        <td><span class="num font-semibold text-navy">{{ formatNumber(c.tax_rate) }}%</span></td>
                        <td><Badge :tone="c.is_active ? 'green' : 'slate'">{{ c.is_active ? 'مفعّل' : 'متوقف' }}</Badge></td>
                        <td><button class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-navy" @click="edit(c)"><Pencil class="size-4" /></button></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-slate-500">تغيير النسبة يُطبّق على المستندات الجديدة فقط؛ كل فاتورة تحفظ النسبة لحظة إصدارها.</p>

        <Modal v-model="open" :title="editingId ? 'تعديل التصنيف' : 'تصنيف جديد'">
            <div class="space-y-4">
                <Field label="اسم التصنيف" id="cn" :error="form.errors.name" required><input id="cn" v-model="form.name" class="field-input" /></Field>
                <div class="grid gap-4 sm:grid-cols-2">
                    <Field label="نسبة الضريبة %" id="ct" :error="form.errors.tax_rate"><input id="ct" v-model="form.tax_rate" type="number" step="0.01" min="0" max="100" dir="ltr" class="field-input text-start" /></Field>
                    <Field label="الترتيب" id="cs" :error="form.errors.sort"><input id="cs" v-model="form.sort" type="number" min="0" dir="ltr" class="field-input text-start" /></Field>
                </div>
                <Toggle v-model="form.is_active" label="مفعّل" />
            </div>
            <template #footer>
                <Btn variant="secondary" @click="open = false">إلغاء</Btn>
                <Btn :loading="form.processing" @click="save">حفظ</Btn>
            </template>
        </Modal>
    </AppLayout>
</template>

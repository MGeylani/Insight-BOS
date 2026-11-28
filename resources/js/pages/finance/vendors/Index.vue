<script setup>
import { ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Plus, Search } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import EmptyState from '@/components/EmptyState.vue';
import FinanceTabs from '@/components/finance/FinanceTabs.vue';
import { usd } from '@/lib/crm';

const props = defineProps({ vendors: Array, total_owed_usd: Number, types: Object, search: String, can: Object });
const q = ref(props.search ?? '');
let t;
watch(q, () => { clearTimeout(t); t = setTimeout(() => router.get('/finance/vendors', q.value ? { search: q.value } : {}, { preserveState: true, replace: true }), 300); });

const open = ref(false);
const form = useForm({ name: '', type: 'company', phone: '', email: '', tax_number: '', payment_details: '', notes: '' });
const save = () => form.post('/finance/vendors', { preserveScroll: true, onSuccess: () => { open.value = false; form.reset(); } });
</script>

<template>
    <AppLayout title="الموردون">
        <PageHeader title="الموردون" subtitle="المطابع والمصورون والمستقلون ومزودو الخدمات، وفواتيرهم والمستحق لهم.">
            <template #actions><Btn @click="open = true"><Plus class="size-4" /> مورد</Btn></template>
        </PageHeader>
        <FinanceTabs />

        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <div class="card p-5"><p class="text-xs text-slate-500">إجمالي المستحق للموردين</p><p class="num mt-1 text-2xl font-bold text-red-600">{{ usd(total_owed_usd) }}</p></div>
        </div>

        <div class="card overflow-hidden">
            <div class="border-b border-slate-100 p-4">
                <div class="relative max-w-xs"><Search class="absolute inset-y-0 start-3 my-auto size-4 text-slate-400" /><input v-model="q" type="search" placeholder="اسم المورد" class="field-input ps-9" /></div>
            </div>
            <div class="overflow-x-auto">
                <table v-if="vendors.length" class="tbl">
                    <thead><tr><th>المورد</th><th>النوع</th><th>الهاتف</th><th>الفواتير الآجلة</th><th>المستحق له</th></tr></thead>
                    <tbody>
                        <tr v-for="v in vendors" :key="v.id" class="cursor-pointer" @click="router.visit(`/finance/vendors/${v.id}`)">
                            <td class="font-medium text-navy">{{ v.name }}</td>
                            <td class="text-slate-600">{{ types[v.type] }}</td>
                            <td dir="ltr" class="text-end text-slate-600">{{ v.phone }}</td>
                            <td class="num text-slate-600">{{ v.bills_count }}</td>
                            <td class="num font-semibold" :class="v.owed_usd > 0 ? 'text-red-600' : 'text-slate-400'">{{ v.owed_usd > 0 ? usd(v.owed_usd) : '—' }}</td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-else title="لا يوجد موردون" text="أضف المورد مرة واحدة، ثم سجّل فواتيره كمصروفات «آجلة» وسدّدها من هنا." />
            </div>
        </div>

        <Modal v-model="open" title="مورد جديد">
            <div class="grid gap-4 sm:grid-cols-2">
                <Field label="الاسم" id="n" :error="form.errors.name" required class="sm:col-span-2"><input id="n" v-model="form.name" class="field-input" /></Field>
                <Field label="النوع" id="t"><select id="t" v-model="form.type" class="field-input"><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select></Field>
                <Field label="الهاتف" id="p" :error="form.errors.phone"><input id="p" v-model="form.phone" dir="ltr" class="field-input" /></Field>
                <Field label="البريد" id="e" :error="form.errors.email"><input id="e" v-model="form.email" type="email" dir="ltr" class="field-input" /></Field>
                <Field label="الرقم الضريبي" id="x"><input id="x" v-model="form.tax_number" dir="ltr" class="field-input" /></Field>
                <Field label="بيانات الدفع" id="d" hint="IBAN، محفظة، اسم المستلم" class="sm:col-span-2"><textarea id="d" v-model="form.payment_details" rows="2" class="field-input" /></Field>
            </div>
            <template #footer><Btn variant="secondary" @click="open = false">تراجع</Btn><Btn :loading="form.processing" @click="save">حفظ</Btn></template>
        </Modal>
    </AppLayout>
</template>

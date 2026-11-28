<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Save, Plus } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Field from '@/components/Field.vue';
import Modal from '@/components/Modal.vue';
import FinanceTabs from '@/components/finance/FinanceTabs.vue';
import { money } from '@/lib/crm';

const props = defineProps({
    expense: Object, categories: Array, accounts: Array, advances: Array, vendors: Array, projects: Array,
    people: Array, costCenters: { type: Array, default: () => [] }, currencies: Array, paidBy: Object, canVendor: Boolean,
});
const editing = computed(() => Boolean(props.expense.id));
const today = new Date().toISOString().slice(0, 10);
const form = useForm({
    date: props.expense.date ?? today, category_id: props.expense.category_id ?? '', description: props.expense.description ?? '',
    amount: props.expense.amount, currency: props.expense.currency ?? 'USD', paid_by: props.expense.paid_by ?? 'employee',
    account_id: props.expense.account_id ?? '', advance_id: props.expense.advance_id ?? '', vendor_id: props.expense.vendor_id ?? '',
    vendor_ref: props.expense.vendor_ref ?? '', due_date: props.expense.due_date ?? '', project_id: props.expense.project_id ?? '', cost_center_id: props.expense.cost_center_id ?? '',
    user_id: props.expense.user_id ?? '', notes: props.expense.notes ?? '',
});

const vendorList = ref([...props.vendors]);
const myAdvances = computed(() => props.advances.filter((a) => !props.people.length || a.user_id === Number(form.user_id)));
const account = computed(() => props.accounts.find((a) => a.id === Number(form.account_id)));
const advance = computed(() => props.advances.find((a) => a.id === Number(form.advance_id)));
// The currency follows the account or the advance the money came from.
watch([account, advance, () => form.paid_by], () => {
    if (form.paid_by === 'account' && account.value) form.currency = account.value.currency;
    if (form.paid_by === 'advance' && advance.value) form.currency = advance.value.currency;
});
const lockedCurrency = computed(() => (form.paid_by === 'account' && account.value) || (form.paid_by === 'advance' && advance.value));

const save = () => {
    const opts = { preserveScroll: true };
    editing.value ? form.put(`/finance/expenses/${props.expense.id}`, opts) : form.post('/finance/expenses', opts);
};

const vOpen = ref(false);
const vForm = ref({ name: '', type: 'company', phone: '' });
const vErr = ref('');
const addVendor = async () => {
    vErr.value = '';
    const res = await fetch('/finance/vendors', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '') },
        body: JSON.stringify(vForm.value),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) { vErr.value = Object.values(data.errors ?? {})[0]?.[0] ?? 'تعذّر الحفظ.'; return; }
    vendorList.value.push(data.vendor);
    form.vendor_id = data.vendor.id;
    vOpen.value = false;
    vForm.value = { name: '', type: 'company', phone: '' };
};
</script>

<template>
    <AppLayout :title="editing ? `تعديل ${expense.number}` : 'مصروف جديد'">
        <PageHeader :title="editing ? `تعديل المصروف ${expense.number}` : 'مصروف جديد'" subtitle="بعد الحفظ أرفق صورة الإيصال أو فاتورة المورد ليُعتمد." />
        <FinanceTabs />

        <div class="card max-w-3xl p-6">
            <div class="grid gap-5 sm:grid-cols-2">
                <Field label="البيان" id="ds" :error="form.errors.description" required class="sm:col-span-2"><input id="ds" v-model="form.description" class="field-input" placeholder="مثال: طباعة رول أب لمعرض STK" /></Field>
                <Field label="التصنيف" id="ct" :error="form.errors.category_id" required>
                    <select id="ct" v-model="form.category_id" class="field-input"><option value="">—</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                </Field>
                <Field label="التاريخ" id="dt" :error="form.errors.date" required><input id="dt" v-model="form.date" type="date" :max="today" dir="ltr" class="field-input" /></Field>
                <Field v-if="people.length" label="الموظف" id="us" :error="form.errors.user_id" hint="من صرف المبلغ أو طلبه"><select id="us" v-model="form.user_id" class="field-input"><option v-for="p in people" :key="p.id" :value="p.id">{{ p.name }}</option></select></Field>
                <Field label="المشروع" id="pr" :error="form.errors.project_id" hint="اختياري؛ يدخل في تكلفة المشروع"><select id="pr" v-model="form.project_id" class="field-input"><option value="">— بلا مشروع —</option><option v-for="p in projects" :key="p.id" :value="p.id">{{ p.number }} · {{ p.name }}</option></select></Field>

                <Field v-if="costCenters.length" label="مركز التكلفة" id="cc" :error="form.errors.cost_center_id" hint="اختياري"><select id="cc" v-model="form.cost_center_id" class="field-input"><option value="">—</option><option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.name }}</option></select></Field>
                <div class="sm:col-span-2">
                    <p class="field-label mb-2">من دفع؟</p>
                    <div class="grid gap-2 sm:grid-cols-4">
                        <label v-for="(l, k) in paidBy" :key="k" class="flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2.5 text-sm" :class="form.paid_by === k ? 'border-brand bg-brand/5 text-navy font-semibold' : 'border-slate-200 text-slate-600'">
                            <input v-model="form.paid_by" type="radio" :value="k" class="sr-only" /> {{ l }}
                        </label>
                    </div>
                    <p v-if="form.errors.paid_by" class="field-error">{{ form.errors.paid_by }}</p>
                </div>

                <Field v-if="form.paid_by === 'account'" label="الحساب" id="ac" :error="form.errors.account_id" required>
                    <select id="ac" v-model="form.account_id" class="field-input"><option value="">—</option><option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }} ({{ a.currency }})</option></select>
                </Field>
                <Field v-if="form.paid_by === 'advance'" label="العهدة / السلفة" id="av" :error="form.errors.advance_id" required>
                    <select id="av" v-model="form.advance_id" class="field-input">
                        <option value="">—</option>
                        <option v-for="a in myAdvances" :key="a.id" :value="a.id">{{ a.number }}<template v-if="people.length"> · {{ a.user }}</template> — متبقٍ {{ money(a.balance, a.currency) }}</option>
                    </select>
                    <p v-if="!myAdvances.length" class="mt-1 text-xs text-slate-500">لا عهدة مفتوحة لهذا الموظف.</p>
                </Field>
                <template v-if="form.paid_by === 'credit'">
                    <Field label="المورد" id="vd" :error="form.errors.vendor_id" required>
                        <div class="flex gap-2">
                            <select id="vd" v-model="form.vendor_id" class="field-input"><option value="">—</option><option v-for="v in vendorList" :key="v.id" :value="v.id">{{ v.name }}</option></select>
                            <Btn v-if="canVendor" variant="secondary" size="sm" title="مورد جديد" @click="vOpen = true"><Plus class="size-4" /></Btn>
                        </div>
                    </Field>
                    <Field label="رقم فاتورة المورد" id="vr" :error="form.errors.vendor_ref"><input id="vr" v-model="form.vendor_ref" dir="ltr" class="field-input" /></Field>
                    <Field label="تاريخ الاستحقاق" id="dd" :error="form.errors.due_date" hint="فارغ = بعد 30 يوماً"><input id="dd" v-model="form.due_date" type="date" dir="ltr" class="field-input" /></Field>
                </template>
                <Field v-else-if="form.paid_by !== 'credit'" label="المورد (اختياري)" id="vd2">
                    <select id="vd2" v-model="form.vendor_id" class="field-input"><option value="">—</option><option v-for="v in vendorList" :key="v.id" :value="v.id">{{ v.name }}</option></select>
                </Field>

                <Field label="المبلغ" id="am" :error="form.errors.amount" required><input id="am" v-model="form.amount" type="number" step="0.01" min="0" dir="ltr" class="field-input" /></Field>
                <Field label="العملة" id="cu" :error="form.errors.currency" :hint="lockedCurrency ? 'عملة الحساب/العهدة' : ''">
                    <select id="cu" v-model="form.currency" class="field-input" :disabled="!!lockedCurrency"><option v-for="c in currencies" :key="c" :value="c">{{ c }}</option></select>
                </Field>
                <Field label="ملاحظات" id="nt" class="sm:col-span-2"><textarea id="nt" v-model="form.notes" rows="2" class="field-input" /></Field>
            </div>
            <div v-if="form.errors.status" class="mt-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ form.errors.status }}</div>
            <div class="mt-6 flex justify-end"><Btn :loading="form.processing" @click="save"><Save class="size-4" /> {{ editing ? 'حفظ' : 'تقديم المصروف' }}</Btn></div>
        </div>

        <Modal v-model="vOpen" title="مورد جديد">
            <div class="grid gap-4">
                <Field label="الاسم" id="vn" :error="vErr" required><input id="vn" v-model="vForm.name" class="field-input" /></Field>
                <Field label="النوع" id="vt"><select id="vt" v-model="vForm.type" class="field-input"><option value="company">شركة</option><option value="freelancer">مستقل</option></select></Field>
                <Field label="الهاتف" id="vp"><input id="vp" v-model="vForm.phone" dir="ltr" class="field-input" /></Field>
            </div>
            <template #footer><Btn variant="secondary" @click="vOpen = false">تراجع</Btn><Btn @click="addVendor">إضافة</Btn></template>
        </Modal>
    </AppLayout>
</template>

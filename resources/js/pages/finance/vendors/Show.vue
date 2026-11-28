<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Plus, HandCoins, Pencil, Archive, Ban, Phone, Mail } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import FinanceTabs from '@/components/finance/FinanceTabs.vue';
import { formatDate } from '@/lib/format';
import { money, usd } from '@/lib/crm';
import { expenseStatusTone, settlementTone } from '@/lib/finance';

const props = defineProps({ vendor: Object, bills: Array, openBills: Array, payments: Array, accounts: Array, methods: Object, types: Object, settlements: Object, statuses: Object, can: Object });
const page = usePage();
const errors = computed(() => Object.values(page.props.errors ?? {}));
const today = new Date().toISOString().slice(0, 10);

const paying = ref(false);
const pay = useForm({ account_id: '', date: today, amount: '', method: 'transfer', reference: '', notes: '', allocations: [] });
const account = computed(() => props.accounts.find((a) => a.id === Number(pay.account_id)));
const openPay = () => {
    pay.reset();
    pay.allocations = props.openBills.map((b) => ({ expense_id: b.id, amount: '', ...b }));
    paying.value = true;
};
const sameCur = computed(() => pay.allocations.filter((a) => account.value && a.currency === account.value.currency));
const fillAll = () => {
    let left = Number(pay.amount) || 0;
    pay.allocations.forEach((a) => {
        if (!account.value || a.currency !== account.value.currency) return;
        const v = Math.min(a.owed, left);
        a.amount = v > 0 ? v : '';
        left -= v;
    });
};
const submitPay = () => pay.transform((d) => ({ ...d, allocations: d.allocations.filter((a) => Number(a.amount) > 0).map((a) => ({ expense_id: a.expense_id, amount: a.amount })) }))
    .post(`/finance/vendors/${props.vendor.id}/pay`, { preserveScroll: true, onSuccess: () => (paying.value = false) });

const reversing = ref(null);
const revForm = useForm({ reason: '' });
const reverse = () => revForm.post(`/finance/vendor-payments/${reversing.value.id}/reverse`, { preserveScroll: true, onSuccess: () => (reversing.value = null) });

const editing = ref(false);
const form = useForm({ ...props.vendor });
const save = () => form.put(`/finance/vendors/${props.vendor.id}`, { preserveScroll: true, onSuccess: () => (editing.value = false) });
const archive = () => confirm('أرشفة المورد؟') && router.delete(`/finance/vendors/${props.vendor.id}`);
</script>

<template>
    <AppLayout :title="vendor.name">
        <PageHeader :title="vendor.name" :subtitle="types[vendor.type]">
            <template #actions>
                <Btn v-if="can.bill" variant="secondary" :href="`/finance/expenses/create?vendor_id=${vendor.id}`"><Plus class="size-4" /> فاتورة مورد</Btn>
                <Btn v-if="can.pay" @click="openPay"><HandCoins class="size-4" /> دفعة للمورد</Btn>
                <Btn v-if="can.edit" variant="ghost" @click="editing = true"><Pencil class="size-4" /></Btn>
                <Btn v-if="can.edit" variant="ghost" @click="archive"><Archive class="size-4" /></Btn>
            </template>
        </PageHeader>
        <FinanceTabs />
        <div v-if="errors.length" class="mb-6 rounded-2xl bg-red-50 p-4 text-sm text-red-800"><p v-for="x in errors" :key="x">{{ x }}</p></div>

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <div class="card overflow-hidden">
                    <h3 class="border-b border-slate-100 px-5 py-3 font-semibold text-navy">الفواتير والمصروفات</h3>
                    <div class="overflow-x-auto">
                        <table class="tbl">
                            <thead><tr><th>الرقم</th><th>التاريخ</th><th>البيان</th><th>المبلغ</th><th>المتبقي</th><th>الحالة</th></tr></thead>
                            <tbody>
                                <tr v-for="b in bills" :key="b.id" class="cursor-pointer" @click="router.visit(`/finance/expenses/${b.id}`)">
                                    <td class="num whitespace-nowrap font-semibold text-navy">{{ b.number }}<p v-if="b.vendor_ref" class="text-xs font-normal text-slate-500">#{{ b.vendor_ref }}</p></td>
                                    <td class="whitespace-nowrap text-slate-600">{{ formatDate(b.date) }}</td>
                                    <td>{{ b.description }}<p v-if="b.project" class="text-xs text-slate-500">{{ b.project.number }}</p></td>
                                    <td class="num whitespace-nowrap">{{ money(b.amount, b.currency) }}</td>
                                    <td class="num whitespace-nowrap" :class="b.owed > 0 ? 'font-semibold text-red-600' : 'text-slate-400'">{{ b.owed > 0 ? money(b.owed, b.currency) : '—' }}</td>
                                    <td class="whitespace-nowrap"><Badge :tone="expenseStatusTone[b.status]">{{ statuses[b.status] }}</Badge> <Badge v-if="b.settlement" :tone="settlementTone[b.settlement]">{{ settlements[b.settlement] }}</Badge></td>
                                </tr>
                                <tr v-if="!bills.length"><td colspan="6" class="py-8 text-center text-slate-500">لا فواتير بعد.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card overflow-hidden">
                    <h3 class="border-b border-slate-100 px-5 py-3 font-semibold text-navy">الدفعات</h3>
                    <ul class="divide-y divide-slate-100 text-sm">
                        <li v-for="p in payments" :key="p.id" class="flex items-center justify-between gap-2 px-5 py-3" :class="{ 'opacity-50': p.status === 'reversed' }">
                            <div>
                                <p class="num font-semibold" :class="{ 'line-through': p.status === 'reversed' }">{{ p.number }} · {{ money(p.amount, p.currency) }}</p>
                                <p class="text-xs text-slate-500">{{ formatDate(p.date) }} · {{ p.account }} · {{ methods[p.method] }}<template v-if="p.reference"> · {{ p.reference }}</template></p>
                                <p v-if="p.status === 'reversed'" class="text-xs text-slate-500">معكوسة: {{ p.reverse_reason }}</p>
                                <p v-else-if="p.unallocated > 0" class="text-xs text-amber-700">غير مسوّى منها {{ money(p.unallocated, p.currency) }} (دفعة مقدّمة)</p>
                            </div>
                            <Btn v-if="can.reverse && p.status === 'posted'" size="sm" variant="ghost" class="!text-red-600" @click="reversing = p; revForm.reset()"><Ban class="size-3.5" /> عكس</Btn>
                        </li>
                        <li v-if="!payments.length" class="px-5 py-6 text-center text-slate-500">لا دفعات بعد.</li>
                    </ul>
                </div>
            </div>
            <div class="space-y-6">
                <div class="card p-5">
                    <p class="text-xs text-slate-500">المستحق له</p>
                    <p class="num mt-1 text-2xl font-bold" :class="vendor.owed_usd > 0 ? 'text-red-600' : 'text-emerald-700'">{{ usd(vendor.owed_usd) }}</p>
                    <dl class="mt-4 space-y-2 text-sm text-slate-600">
                        <div v-if="vendor.phone" class="flex items-center gap-2"><Phone class="size-4 text-slate-400" /><span dir="ltr">{{ vendor.phone }}</span></div>
                        <div v-if="vendor.email" class="flex items-center gap-2"><Mail class="size-4 text-slate-400" /><span dir="ltr">{{ vendor.email }}</span></div>
                        <div v-if="vendor.tax_number">الرقم الضريبي: <span class="num">{{ vendor.tax_number }}</span></div>
                        <p v-if="vendor.payment_details" class="whitespace-pre-line rounded-xl bg-slate-50 p-3">{{ vendor.payment_details }}</p>
                        <p v-if="vendor.notes" class="whitespace-pre-line">{{ vendor.notes }}</p>
                    </dl>
                </div>
            </div>
        </div>

        <Modal v-model="paying" title="دفعة للمورد" wide>
            <div class="grid gap-4 sm:grid-cols-3">
                <Field label="من حساب" id="pa" :error="pay.errors.account_id" required><select id="pa" v-model="pay.account_id" class="field-input"><option value="">—</option><option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }} ({{ a.currency }})</option></select></Field>
                <Field label="المبلغ" id="pm" :error="pay.errors.amount" required><input id="pm" v-model="pay.amount" type="number" step="0.01" min="0" dir="ltr" class="field-input" @change="fillAll" /></Field>
                <Field label="التاريخ" id="pd" :error="pay.errors.date"><input id="pd" v-model="pay.date" type="date" :max="today" dir="ltr" class="field-input" /></Field>
                <Field label="الطريقة" id="pw"><select id="pw" v-model="pay.method" class="field-input"><option v-for="(l, k) in methods" :key="k" :value="k">{{ l }}</option></select></Field>
                <Field label="المرجع" id="pr" class="sm:col-span-2"><input id="pr" v-model="pay.reference" class="field-input" /></Field>
            </div>
            <div v-if="pay.allocations.length" class="mt-5">
                <p class="mb-2 text-sm font-semibold text-navy">تسديد الفواتير <span class="text-xs font-normal text-slate-500">(بعملة الحساب؛ ما لا يُوزَّع يبقى دفعة مقدّمة)</span></p>
                <p v-if="pay.errors.allocations" class="field-error mb-2">{{ pay.errors.allocations }}</p>
                <table class="tbl">
                    <thead><tr><th>الفاتورة</th><th>الاستحقاق</th><th>المتبقي</th><th>المبلغ</th></tr></thead>
                    <tbody>
                        <tr v-for="a in pay.allocations" :key="a.expense_id" :class="{ 'opacity-40': account && a.currency !== account.currency }">
                            <td class="num">{{ a.number }} <span v-if="a.vendor_ref" class="text-xs text-slate-500">#{{ a.vendor_ref }}</span></td>
                            <td :class="a.overdue ? 'text-red-600' : 'text-slate-600'">{{ formatDate(a.due_date) }}</td>
                            <td class="num">{{ money(a.owed, a.currency) }}</td>
                            <td><input v-model="a.amount" type="number" step="0.01" min="0" :max="a.owed" dir="ltr" class="field-input w-32" :disabled="!account || a.currency !== account.currency" /></td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="account && !sameCur.length" class="mt-2 text-xs text-amber-700">لا فواتير بعملة {{ account.currency }}؛ تُسجَّل الدفعة مقدّمة ويُسوّى منها لاحقاً.</p>
            </div>
            <template #footer><Btn variant="secondary" @click="paying = false">تراجع</Btn><Btn :loading="pay.processing" @click="submitPay">تسجيل الدفعة</Btn></template>
        </Modal>

        <Modal :model-value="!!reversing" title="عكس الدفعة" @update:model-value="(v) => !v && (reversing = null)">
            <Field label="السبب" id="rv" :error="revForm.errors.reason" required><textarea id="rv" v-model="revForm.reason" rows="3" class="field-input" /></Field>
            <template #footer><Btn variant="secondary" @click="reversing = null">تراجع</Btn><Btn variant="danger" :loading="revForm.processing" @click="reverse">عكس</Btn></template>
        </Modal>

        <Modal v-model="editing" title="تعديل المورد">
            <div class="grid gap-4 sm:grid-cols-2">
                <Field label="الاسم" id="en" :error="form.errors.name" required class="sm:col-span-2"><input id="en" v-model="form.name" class="field-input" /></Field>
                <Field label="النوع" id="et"><select id="et" v-model="form.type" class="field-input"><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select></Field>
                <Field label="الهاتف" id="ep"><input id="ep" v-model="form.phone" dir="ltr" class="field-input" /></Field>
                <Field label="البريد" id="ee" :error="form.errors.email"><input id="ee" v-model="form.email" type="email" dir="ltr" class="field-input" /></Field>
                <Field label="الرقم الضريبي" id="ex"><input id="ex" v-model="form.tax_number" dir="ltr" class="field-input" /></Field>
                <Field label="بيانات الدفع" id="ed" class="sm:col-span-2"><textarea id="ed" v-model="form.payment_details" rows="2" class="field-input" /></Field>
                <Field label="ملاحظات" id="eo" class="sm:col-span-2"><textarea id="eo" v-model="form.notes" rows="2" class="field-input" /></Field>
            </div>
            <template #footer><Btn variant="secondary" @click="editing = false">تراجع</Btn><Btn :loading="form.processing" @click="save">حفظ</Btn></template>
        </Modal>
    </AppLayout>
</template>

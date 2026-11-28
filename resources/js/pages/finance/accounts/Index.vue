<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Plus, ArrowLeftRight, Pencil, Archive, ArchiveRestore, Banknote, Landmark, Wallet, Undo2 } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import Toggle from '@/components/Toggle.vue';
import EmptyState from '@/components/EmptyState.vue';
import FinanceTabs from '@/components/finance/FinanceTabs.vue';
import { formatDate } from '@/lib/format';
import { money, usd } from '@/lib/crm';
import { useAuth } from '@/lib/auth';

const props = defineProps({ accounts: Array, transfers: Array, types: Object, currencies: Array, canManage: Boolean });
const { perms } = useAuth();
const canReverse = computed(() => Boolean(perms.value['*']) || perms.value['finance.delete'] === 'all');
const icons = { cash: Banknote, bank: Landmark, wallet: Wallet };
const today = new Date().toISOString().slice(0, 10);

const active = computed(() => props.accounts.filter((a) => !a.archived));
const archived = computed(() => props.accounts.filter((a) => a.archived));
const totalUsd = computed(() => active.value.reduce((s, a) => s + (a.balance_usd ?? 0), 0));

const editing = ref(null);
const form = useForm({ name: '', type: 'cash', currency: 'USD', opening_balance: 0, opening_date: '', details: '', is_active: true, sort: 0 });
const openForm = (a = null) => {
    editing.value = a ?? {};
    form.defaults(a ? { name: a.name, type: a.type, currency: a.currency, opening_balance: a.opening_balance, opening_date: a.opening_date ?? '', details: a.details ?? '', is_active: a.is_active, sort: a.sort } : { name: '', type: 'cash', currency: 'USD', opening_balance: 0, opening_date: today, details: '', is_active: true, sort: 0 });
    form.reset();
    form.clearErrors();
};
const save = () => {
    const opts = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    editing.value?.id ? form.put(`/finance/accounts/${editing.value.id}`, opts) : form.post('/finance/accounts', opts);
};
const archive = (a) => confirm(`أرشفة «${a.name}»؟ تبقى حركاته في السجلات ولا يظهر في الاختيار.`) && router.delete(`/finance/accounts/${a.id}`, { preserveScroll: true });
const restore = (a) => router.post(`/finance/accounts/${a.id}/restore`, {}, { preserveScroll: true });

const transferring = ref(false);
const tForm = useForm({ from_account_id: '', to_account_id: '', date: today, amount: '', to_amount: '', reference: '', notes: '' });
const fromAcc = computed(() => active.value.find((a) => a.id === Number(tForm.from_account_id)));
const toAcc = computed(() => active.value.find((a) => a.id === Number(tForm.to_account_id)));
const crossCurrency = computed(() => fromAcc.value && toAcc.value && fromAcc.value.currency !== toAcc.value.currency);
const transfer = () => tForm.transform((d) => ({ ...d, to_amount: crossCurrency.value ? d.to_amount : null }))
    .post('/finance/transfers', { preserveScroll: true, onSuccess: () => { transferring.value = false; tForm.reset(); } });
const reverseTransfer = (t) => confirm('عكس هذا التحويل؟ يعود المبلغ إلى الحساب المصدر.') && router.post(`/finance/transfers/${t.id}/reverse`, {}, { preserveScroll: true });
</script>

<template>
    <AppLayout title="الصناديق والحسابات">
        <PageHeader title="المالية" subtitle="الصناديق النقدية والحسابات البنكية وأرصدتها والتحويلات بينها.">
            <template #actions>
                <Btn v-if="canManage && active.length > 1" variant="secondary" @click="transferring = true"><ArrowLeftRight class="size-4" /> تحويل</Btn>
                <Btn v-if="canManage" @click="openForm()"><Plus class="size-4" /> حساب</Btn>
            </template>
        </PageHeader>
        <FinanceTabs />

        <div v-if="active.length" class="mb-4 text-sm text-slate-600">مجموع الأرصدة بالدولار (بسعر اليوم): <span class="num font-bold text-navy">{{ usd(totalUsd) }}</span></div>
        <div v-if="active.length" class="mb-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <div v-for="a in active" :key="a.id" class="card p-5" :class="{ 'opacity-60': !a.is_active }">
                <div class="flex items-start justify-between gap-2">
                    <Link :href="`/finance/accounts/${a.id}`" class="flex items-center gap-3">
                        <span class="flex size-10 items-center justify-center rounded-xl bg-navy/5 text-navy"><component :is="icons[a.type]" class="size-5" /></span>
                        <span><span class="block font-semibold text-navy hover:underline">{{ a.name }}</span><span class="text-xs text-slate-500">{{ types[a.type] }} · {{ a.currency }}</span></span>
                    </Link>
                    <div v-if="canManage" class="flex gap-1">
                        <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-navy" title="تعديل" @click="openForm(a)"><Pencil class="size-4" /></button>
                        <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-red-600" title="أرشفة" @click="archive(a)"><Archive class="size-4" /></button>
                    </div>
                </div>
                <p class="num mt-4 text-2xl font-bold" :class="a.balance < 0 ? 'text-red-600' : 'text-slate-800'">{{ money(a.balance, a.currency) }}</p>
                <p v-if="a.currency !== 'USD' && a.balance_usd !== null" class="num text-xs text-slate-500">{{ usd(a.balance_usd) }}</p>
                <Badge v-if="!a.is_active" tone="slate" class="mt-2">موقوف</Badge>
            </div>
        </div>
        <EmptyState v-else title="لا توجد حسابات" text="أضف الصندوق النقدي والحسابات البنكية لتسجيل المقبوضات عليها.">
            <Btn v-if="canManage" @click="openForm()"><Plus class="size-4" /> حساب</Btn>
        </EmptyState>

        <div v-if="transfers.length" class="card mb-6 overflow-hidden">
            <h3 class="border-b border-slate-100 px-5 py-3 font-semibold text-navy">آخر التحويلات</h3>
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead><tr><th>التاريخ</th><th>من</th><th>إلى</th><th>المبلغ</th><th>المرجع</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="t in transfers" :key="t.id" :class="{ 'opacity-50 line-through': t.reversed }">
                            <td class="whitespace-nowrap text-slate-600">{{ formatDate(t.date) }}</td>
                            <td>{{ t.from?.name }}</td>
                            <td>{{ t.to?.name }}</td>
                            <td class="num whitespace-nowrap">{{ money(t.amount, t.from?.currency) }}<span v-if="t.from?.currency !== t.to?.currency" class="text-xs text-slate-500"> ← {{ money(t.to_amount, t.to?.currency) }}</span></td>
                            <td class="text-slate-600">{{ t.reference }}</td>
                            <td><button v-if="!t.reversed && canReverse" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-red-600" title="عكس" @click="reverseTransfer(t)"><Undo2 class="size-4" /></button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="archived.length" class="card p-5">
            <h3 class="mb-3 font-semibold text-slate-600">حسابات مؤرشفة</h3>
            <ul class="space-y-2 text-sm">
                <li v-for="a in archived" :key="a.id" class="flex items-center justify-between">
                    <Link :href="`/finance/accounts/${a.id}`" class="text-slate-600 hover:underline">{{ a.name }} ({{ a.currency }}) · <span class="num">{{ money(a.balance, a.currency) }}</span></Link>
                    <Btn v-if="canManage" size="sm" variant="ghost" @click="restore(a)"><ArchiveRestore class="size-3.5" /> إعادة</Btn>
                </li>
            </ul>
        </div>

        <Modal :model-value="!!editing" :title="editing?.id ? 'تعديل الحساب' : 'حساب جديد'" @update:model-value="(v) => !v && (editing = null)">
            <div class="grid gap-4 sm:grid-cols-2">
                <Field label="الاسم" id="an" :error="form.errors.name" required class="sm:col-span-2"><input id="an" v-model="form.name" class="field-input" placeholder="صندوق المكتب، بنك زراعات…" /></Field>
                <Field label="النوع" id="at" :error="form.errors.type"><select id="at" v-model="form.type" class="field-input"><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select></Field>
                <Field label="العملة" id="ac" :error="form.errors.currency"><select id="ac" v-model="form.currency" class="field-input"><option v-for="c in currencies" :key="c" :value="c">{{ c }}</option></select></Field>
                <Field label="الرصيد الافتتاحي" id="ao" :error="form.errors.opening_balance"><input id="ao" v-model="form.opening_balance" type="number" step="0.01" dir="ltr" class="field-input" /></Field>
                <Field label="بتاريخ" id="ad" :error="form.errors.opening_date"><input id="ad" v-model="form.opening_date" type="date" dir="ltr" class="field-input" /></Field>
                <Field label="تفاصيل" id="ax" hint="اسم البنك، IBAN، ملاحظات (تظهر لفريق المالية فقط)" class="sm:col-span-2"><textarea id="ax" v-model="form.details" rows="2" class="field-input" /></Field>
                <div class="sm:col-span-2"><Toggle v-model="form.is_active" label="نشط (يظهر عند تسجيل السندات)" /></div>
            </div>
            <template #footer><Btn variant="secondary" @click="editing = null">تراجع</Btn><Btn :loading="form.processing" @click="save">حفظ</Btn></template>
        </Modal>

        <Modal v-model="transferring" title="تحويل بين الحسابات">
            <div class="grid gap-4 sm:grid-cols-2">
                <Field label="من" id="tf" :error="tForm.errors.from_account_id" required><select id="tf" v-model="tForm.from_account_id" class="field-input"><option value="">—</option><option v-for="a in active" :key="a.id" :value="a.id">{{ a.name }} ({{ a.currency }})</option></select></Field>
                <Field label="إلى" id="tt" :error="tForm.errors.to_account_id" required><select id="tt" v-model="tForm.to_account_id" class="field-input"><option value="">—</option><option v-for="a in active" :key="a.id" :value="a.id" :disabled="a.id === Number(tForm.from_account_id)">{{ a.name }} ({{ a.currency }})</option></select></Field>
                <Field :label="`المبلغ${fromAcc ? ' (' + fromAcc.currency + ')' : ''}`" id="ta" :error="tForm.errors.amount" required><input id="ta" v-model="tForm.amount" type="number" step="0.01" min="0" dir="ltr" class="field-input" /></Field>
                <Field v-if="crossCurrency" :label="`الواصل (${toAcc.currency})`" id="tw" :error="tForm.errors.to_amount" required hint="ما وصل فعلاً بعد الصرف"><input id="tw" v-model="tForm.to_amount" type="number" step="0.01" min="0" dir="ltr" class="field-input" /></Field>
                <Field label="التاريخ" id="td" :error="tForm.errors.date"><input id="td" v-model="tForm.date" type="date" :max="today" dir="ltr" class="field-input" /></Field>
                <Field label="المرجع" id="tr"><input id="tr" v-model="tForm.reference" class="field-input" /></Field>
                <Field label="ملاحظات" id="tn" class="sm:col-span-2"><textarea id="tn" v-model="tForm.notes" rows="2" class="field-input" /></Field>
            </div>
            <template #footer><Btn variant="secondary" @click="transferring = false">تراجع</Btn><Btn :loading="tForm.processing" @click="transfer">تحويل</Btn></template>
        </Modal>
    </AppLayout>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Save, Wand2, AlertTriangle } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Field from '@/components/Field.vue';
import FinanceTabs from '@/components/finance/FinanceTabs.vue';
import { formatDate, formatNumber } from '@/lib/format';
import { money } from '@/lib/crm';
import { r2 } from '@/lib/finance';

const props = defineProps({ clients: Array, accounts: Array, methods: Object, preset: Object });
const today = new Date().toISOString().slice(0, 10);
const form = useForm({
    client_id: props.preset.client_id ?? '', account_id: props.accounts[0]?.id ?? '', date: today, amount: '',
    method: 'transfer', reference: '', notes: '', allocations: [],
});
const account = computed(() => props.accounts.find((a) => a.id === Number(form.account_id)));
const currency = computed(() => account.value?.currency ?? 'USD');

const open = ref([]);
const credits = ref([]);
const rows = ref([]); // { invoice, amount, payment_amount }
async function loadClient() {
    open.value = []; credits.value = []; rows.value = [];
    if (!form.client_id) return;
    const res = await fetch(`/finance/client-open/${form.client_id}`, { headers: { Accept: 'application/json' } });
    const d = await res.json();
    open.value = d.invoices;
    credits.value = d.credits;
    rows.value = d.invoices.map((i) => ({ invoice: i, amount: '', payment_amount: '' }));
    if (props.preset.invoice_id) {
        const row = rows.value.find((r) => r.invoice.id === props.preset.invoice_id);
        if (row) {
            row.amount = row.invoice.balance;
            if (row.invoice.currency === currency.value) form.amount = row.invoice.balance;
            else { await convertRow(row); form.amount = row.payment_amount; }
        }
    }
}
watch(() => form.client_id, loadClient, { immediate: true });

/** Part of the receipt an allocation uses (receipt currency). */
const used = (r) => (r.invoice.currency === currency.value ? Number(r.amount || 0) : Number(r.payment_amount || 0));
const allocated = computed(() => r2(rows.value.reduce((s, r) => s + used(r), 0)));
const left = computed(() => r2(Number(form.amount || 0) - allocated.value));

async function convertRow(r) {
    if (!r.amount || r.invoice.currency === currency.value) { r.payment_amount = ''; return; }
    const q = new URLSearchParams({ amount: r.amount, from: r.invoice.currency, to: currency.value, date: form.date });
    const res = await fetch(`/finance/convert?${q}`, { headers: { Accept: 'application/json' } });
    if (res.ok) r.payment_amount = (await res.json()).amount;
}
watch(currency, () => rows.value.forEach(convertRow));

/** Fill oldest invoices first with what is left (same-currency invoices only). */
function autoFill() {
    let rest = Number(form.amount || 0);
    rows.value.forEach((r) => {
        if (r.invoice.currency !== currency.value) return;
        const take = r2(Math.max(0, Math.min(rest, r.invoice.balance)));
        r.amount = take || '';
        rest = r2(rest - take);
    });
}

function save() {
    form.transform((d) => ({
        ...d,
        back: props.preset.invoice_id ? 'invoice' : null,
        allocations: rows.value.filter((r) => Number(r.amount) > 0).map((r) => ({
            invoice_id: r.invoice.id, amount: Number(r.amount), payment_amount: r.invoice.currency === currency.value ? null : Number(r.payment_amount) || null,
        })),
    })).post('/finance/payments');
}
const errs = computed(() => Object.entries(form.errors).filter(([k]) => k.startsWith('allocations')).map(([, v]) => v));
</script>

<template>
    <AppLayout title="سند قبض">
        <PageHeader title="سند قبض جديد" subtitle="مبلغ مستلم من عميل في أحد الحسابات، ويُسوّى على فواتيره. ما لا يُسوّى يبقى رصيداً للعميل.">
            <template #actions>
                <Btn variant="secondary" href="/finance/payments">إلغاء</Btn>
                <Btn :loading="form.processing" :disabled="left < 0" @click="save"><Save class="size-4" /> حفظ السند</Btn>
            </template>
        </PageHeader>
        <FinanceTabs />

        <div v-if="!accounts.length" class="mb-6 flex items-center gap-2 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900">
            <AlertTriangle class="size-4" /> لا توجد حسابات. أضف صندوقاً أو حساباً بنكياً من «الصناديق والحسابات» أولاً.
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <div class="card grid gap-4 p-5 sm:grid-cols-2">
                    <Field label="العميل" id="p-client" :error="form.errors.client_id" required>
                        <select id="p-client" v-model="form.client_id" class="field-input"><option value="">اختر العميل…</option><option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                    </Field>
                    <Field label="إلى حساب" id="p-acc" :error="form.errors.account_id" required hint="عملة السند هي عملة الحساب.">
                        <select id="p-acc" v-model="form.account_id" class="field-input"><option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }} ({{ a.currency }})</option></select>
                    </Field>
                    <Field :label="`المبلغ (${currency})`" id="p-amount" :error="form.errors.amount" required>
                        <input id="p-amount" v-model="form.amount" type="number" min="0.01" step="0.01" dir="ltr" class="field-input text-start" />
                    </Field>
                    <Field label="التاريخ" id="p-date" :error="form.errors.date" required><input id="p-date" v-model="form.date" type="date" :max="today" dir="ltr" class="field-input" /></Field>
                    <Field label="الطريقة" id="p-method" :error="form.errors.method" required>
                        <select id="p-method" v-model="form.method" class="field-input"><option v-for="(l, k) in methods" :key="k" :value="k">{{ l }}</option></select>
                    </Field>
                    <Field label="المرجع" id="p-ref" :error="form.errors.reference" hint="رقم الحوالة أو الشيك أو الإيصال."><input id="p-ref" v-model="form.reference" dir="ltr" class="field-input text-start" /></Field>
                    <Field label="البيان" id="p-notes" :error="form.errors.notes" class="sm:col-span-2"><textarea id="p-notes" v-model="form.notes" rows="2" class="field-input" /></Field>
                </div>

                <div class="card overflow-hidden">
                    <div class="flex items-center gap-2 border-b border-slate-100 p-4">
                        <h3 class="me-auto font-semibold text-navy">تسوية الفواتير</h3>
                        <Btn v-if="rows.length" variant="secondary" size="sm" :disabled="!form.amount" @click="autoFill"><Wand2 class="size-4" /> الأقدم أولاً</Btn>
                    </div>
                    <p v-for="e in errs" :key="e" class="field-error px-4 pt-3">{{ e }}</p>
                    <table v-if="rows.length" class="tbl">
                        <thead><tr><th>الفاتورة</th><th>الاستحقاق</th><th>المتبقي</th><th>يُسوّى منها</th></tr></thead>
                        <tbody>
                            <tr v-for="r in rows" :key="r.invoice.id">
                                <td><p class="num font-semibold text-navy">{{ r.invoice.number }}</p><p class="text-xs text-slate-500">{{ r.invoice.title }}</p></td>
                                <td class="whitespace-nowrap" :class="r.invoice.overdue ? 'font-semibold text-red-600' : 'text-slate-600'">{{ formatDate(r.invoice.due_date) }}</td>
                                <td class="num whitespace-nowrap">{{ money(r.invoice.balance, r.invoice.currency) }}</td>
                                <td class="w-56">
                                    <div class="flex items-center gap-1">
                                        <input v-model="r.amount" type="number" min="0" :max="r.invoice.balance" step="0.01" dir="ltr" class="field-input py-1.5 text-start" @change="convertRow(r)" />
                                        <span class="text-xs text-slate-500">{{ r.invoice.currency }}</span>
                                    </div>
                                    <div v-if="r.amount && r.invoice.currency !== currency" class="mt-1 flex items-center gap-1 text-xs text-slate-500">
                                        = <input v-model="r.payment_amount" type="number" min="0" step="0.01" dir="ltr" class="field-input w-28 py-1 text-start text-xs" /> {{ currency }} من السند
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="px-5 py-8 text-center text-sm text-slate-500">{{ form.client_id ? 'لا فواتير مفتوحة لهذا العميل؛ يبقى المبلغ رصيداً له.' : 'اختر العميل لعرض فواتيره المفتوحة.' }}</p>
                </div>
            </div>

            <div>
                <div class="card sticky top-24 space-y-3 p-5 text-sm">
                    <h3 class="font-semibold text-navy">الملخص</h3>
                    <div class="flex justify-between"><span class="text-slate-500">المبلغ</span><span class="num">{{ money(form.amount || 0, currency) }}</span></div>
                    <div class="flex justify-between"><span class="text-slate-500">مُسوّى على فواتير</span><span class="num">{{ money(allocated, currency) }}</span></div>
                    <div class="flex justify-between border-t border-slate-100 pt-3 font-bold" :class="left < 0 ? 'text-red-600' : 'text-navy'">
                        <span>{{ left < 0 ? 'التسويات أكثر من المبلغ' : 'يبقى رصيداً للعميل' }}</span><span class="num">{{ formatNumber(Math.abs(left)) }}</span>
                    </div>
                    <div v-if="credits.length" class="rounded-xl bg-sky/10 p-3 text-xs text-navy">
                        للعميل رصيد سابق غير مسوّى في <span class="num">{{ credits.length }}</span> سند؛ يمكن تسويته من صفحة السند أو الفاتورة.
                    </div>
                    <Btn class="w-full" :loading="form.processing" :disabled="left < 0" @click="save"><Save class="size-4" /> حفظ السند</Btn>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

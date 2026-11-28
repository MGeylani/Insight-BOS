<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { FileDown, Eye, Undo2, Ban, Link2, Unlink, Building2, Landmark, CalendarDays, UserRound } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import FinanceTabs from '@/components/finance/FinanceTabs.vue';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { money, usd } from '@/lib/crm';

const props = defineProps({ payment: Object, openInvoices: Array, accounts: Array, methods: Object, log: Array });
const page = usePage();
const p = computed(() => props.payment);
const errors = computed(() => Object.values(page.props.errors ?? {}));
const receipt = computed(() => p.value.kind === 'receipt');
const today = new Date().toISOString().slice(0, 10);

const allocating = ref(false);
const allocForm = useForm({ invoice_id: '', amount: '', payment_amount: '' });
const pickInvoice = computed(() => props.openInvoices.find((i) => i.id === Number(allocForm.invoice_id)));
const allocate = () => allocForm.transform((d) => ({ ...d, payment_amount: d.payment_amount || null }))
    .post(`/finance/payments/${p.value.id}/allocate`, { preserveScroll: true, onSuccess: () => { allocating.value = false; allocForm.reset(); } });
const deallocate = (a) => confirm(`فك تسوية ${a.invoice.number}؟ يصبح المبلغ رصيداً للعميل وتعود الفاتورة مفتوحة.`) && router.delete(`/finance/allocations/${a.id}`, { preserveScroll: true });

const refunding = ref(false);
const refundForm = useForm({ amount: '', account_id: p.value.account?.id ?? '', date: today, method: p.value.method, reference: '', notes: '' });
const refund = () => refundForm.post(`/finance/payments/${p.value.id}/refund`, { preserveScroll: true, onSuccess: () => { refunding.value = false; refundForm.reset(); } });

const reversing = ref(false);
const reverseForm = useForm({ reason: '' });
const reverse = () => reverseForm.post(`/finance/payments/${p.value.id}/reverse`, { preserveScroll: true, onSuccess: () => (reversing.value = false) });
const pdf = computed(() => `/finance/payments/${p.value.id}/pdf`);
</script>

<template>
    <AppLayout :title="p.number">
        <PageHeader :title="`${receipt ? 'سند قبض' : 'سند ردّ'} ${p.number}`" :subtitle="p.client?.name">
            <template #actions>
                <a :href="`${pdf}?download=1`" class="inline-flex items-center gap-2 rounded-xl bg-navy px-4 py-2.5 text-sm font-medium text-white hover:bg-navy-700"><FileDown class="size-4" /> PDF</a>
                <a :href="pdf" target="_blank" class="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"><Eye class="size-4" /> عرض</a>
                <Btn v-if="receipt && p.can_edit && p.unallocated > 0" variant="secondary" @click="refunding = true"><Undo2 class="size-4" /> ردّ مبلغ</Btn>
                <Btn v-if="p.can_reverse" variant="ghost" class="!text-red-600" @click="reversing = true"><Ban class="size-4" /> عكس السند</Btn>
            </template>
        </PageHeader>
        <FinanceTabs />

        <div v-if="errors.length" class="mb-6 rounded-2xl bg-red-50 p-4 text-sm text-red-800"><p v-for="e in errors" :key="e">{{ e }}</p></div>
        <div v-if="p.status === 'reversed'" class="mb-6 rounded-2xl bg-slate-100 p-4 text-sm text-slate-700">
            <strong>معكوس</strong> في {{ formatDateTime(p.reversed_at) }} بواسطة {{ p.reverser ?? '—' }}<template v-if="p.reverse_reason"> — {{ p.reverse_reason }}</template>
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <div class="card overflow-hidden">
                    <div class="brand-gradient h-1.5"></div>
                    <div class="grid gap-6 p-6 sm:grid-cols-3">
                        <div><p class="text-xs text-slate-500">المبلغ</p><p class="num text-2xl font-bold" :class="receipt ? 'text-emerald-700' : 'text-red-600'">{{ money(p.amount, p.currency) }}</p><p v-if="p.currency !== 'USD'" class="num text-xs text-slate-500">{{ usd(p.amount_usd) }} · 1 USD = {{ formatNumber(p.fx_rate, 4) }}</p></div>
                        <div v-if="receipt"><p class="text-xs text-slate-500">غير مسوّى (رصيد العميل)</p><p class="num text-2xl font-bold text-navy">{{ money(p.unallocated, p.currency) }}</p></div>
                        <div><p class="text-xs text-slate-500">الطريقة</p><p class="font-semibold text-slate-800">{{ methods[p.method] }}</p><p v-if="p.reference" dir="ltr" class="text-end text-xs text-slate-500">{{ p.reference }}</p></div>
                    </div>
                    <p v-if="p.notes" class="border-t border-slate-100 px-6 py-4 text-sm text-slate-700">{{ p.notes }}</p>
                </div>

                <div v-if="receipt" class="card overflow-hidden">
                    <div class="flex items-center gap-2 border-b border-slate-100 p-4">
                        <h3 class="me-auto font-semibold text-navy">الفواتير المسوّاة</h3>
                        <Btn v-if="p.can_edit && p.unallocated > 0 && openInvoices.length" variant="secondary" size="sm" @click="allocating = true"><Link2 class="size-4" /> تسوية فاتورة</Btn>
                    </div>
                    <table v-if="p.allocations.length" class="tbl">
                        <thead><tr><th>الفاتورة</th><th>المبلغ على الفاتورة</th><th>من السند</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="a in p.allocations" :key="a.id">
                                <td><Link :href="`/finance/invoices/${a.invoice.id}`" class="num font-semibold text-brand hover:underline">{{ a.invoice.number }}</Link><p class="text-xs text-slate-500">{{ a.invoice.title }}</p></td>
                                <td class="num">{{ money(a.amount, a.invoice.currency) }}</td>
                                <td class="num">{{ money(a.payment_amount, p.currency) }}</td>
                                <td class="text-end"><button v-if="p.can_edit" type="button" class="rounded p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600" title="فك التسوية" @click="deallocate(a)"><Unlink class="size-4" /></button></td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="px-5 py-8 text-center text-sm text-slate-500">لم يُسوَّ هذا السند على أي فاتورة بعد.</p>
                </div>

                <div v-if="p.refunds.length" class="card p-5">
                    <h3 class="mb-3 font-semibold text-navy">مبالغ مردودة من هذا السند</h3>
                    <ul class="divide-y divide-slate-100 text-sm">
                        <li v-for="r in p.refunds" :key="r.id" class="flex items-center gap-3 py-2" :class="{ 'opacity-50 line-through': r.status === 'reversed' }">
                            <Link :href="`/finance/payments/${r.id}`" class="num font-semibold text-brand hover:underline">{{ r.number }}</Link>
                            <span class="text-slate-500">{{ formatDate(r.date) }}</span>
                            <span class="num ms-auto font-semibold text-red-600">− {{ money(r.amount, r.currency) }}</span>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="space-y-6">
                <div class="card p-5">
                    <dl class="space-y-3 text-sm">
                        <div class="flex items-center gap-2"><Building2 class="size-4 text-slate-400" /><Link :href="`/crm/clients/${p.client?.id}`" class="text-brand hover:underline">{{ p.client?.name }}</Link></div>
                        <div class="flex items-center gap-2"><Landmark class="size-4 text-slate-400" />{{ p.account?.name }} ({{ p.currency }})</div>
                        <div class="flex items-center gap-2"><CalendarDays class="size-4 text-slate-400" />{{ formatDate(p.date) }}</div>
                        <div class="flex items-center gap-2"><UserRound class="size-4 text-slate-400" />سجّله: {{ p.creator ?? '—' }}</div>
                        <div v-if="p.refund_of" class="text-xs text-slate-500">ردّ من السند <Link :href="`/finance/payments/${p.refund_of.id}`" class="text-brand hover:underline">{{ p.refund_of.number }}</Link></div>
                        <div><Badge v-if="p.status === 'reversed'">معكوس</Badge></div>
                    </dl>
                </div>
                <div class="card p-5">
                    <h3 class="mb-3 font-semibold text-navy">السجل</h3>
                    <ol class="relative space-y-4 border-s border-slate-200 ps-4">
                        <li v-for="l in log" :key="l.id" class="text-sm">
                            <span class="absolute -start-1.5 mt-1.5 size-3 rounded-full border-2 border-white bg-brand" />
                            <p class="text-slate-700">{{ l.description }}</p>
                            <p class="text-xs text-slate-500">{{ l.user ?? '—' }} · {{ formatDateTime(l.created_at) }}</p>
                        </li>
                    </ol>
                </div>
            </div>
        </div>

        <Modal v-model="allocating" title="تسوية فاتورة من هذا السند">
            <div class="space-y-4">
                <Field label="الفاتورة" id="al-inv" :error="allocForm.errors.invoice_id" required>
                    <select id="al-inv" v-model="allocForm.invoice_id" class="field-input" @change="allocForm.amount = pickInvoice?.currency === p.currency ? Math.min(pickInvoice.balance, p.unallocated) : pickInvoice?.balance">
                        <option value="">اختر…</option>
                        <option v-for="i in openInvoices" :key="i.id" :value="i.id">{{ i.number }} — المتبقي {{ money(i.balance, i.currency) }}</option>
                    </select>
                </Field>
                <Field :label="`المبلغ على الفاتورة (${pickInvoice?.currency ?? ''})`" id="al-amt" :error="allocForm.errors.amount || allocForm.errors.allocations" required>
                    <input id="al-amt" v-model="allocForm.amount" type="number" min="0.01" step="0.01" dir="ltr" class="field-input text-start" />
                </Field>
                <Field v-if="pickInvoice && pickInvoice.currency !== p.currency" :label="`المستخدم من السند (${p.currency})`" id="al-pay" hint="فارغ = بسعر صرف تاريخ السند.">
                    <input id="al-pay" v-model="allocForm.payment_amount" type="number" min="0.01" step="0.01" dir="ltr" class="field-input text-start" />
                </Field>
                <p class="text-xs text-slate-500">غير المسوّى من السند: <span class="num">{{ money(p.unallocated, p.currency) }}</span></p>
            </div>
            <template #footer><Btn variant="secondary" @click="allocating = false">إلغاء</Btn><Btn :loading="allocForm.processing" @click="allocate">تسوية</Btn></template>
        </Modal>

        <Modal v-model="refunding" title="ردّ مبلغ للعميل">
            <div class="grid gap-4 sm:grid-cols-2">
                <Field :label="`المبلغ (${p.currency})`" id="rf-amt" :error="refundForm.errors.amount" :hint="`حتى ${formatNumber(p.unallocated)} (غير المسوّى)`" required>
                    <input id="rf-amt" v-model="refundForm.amount" type="number" min="0.01" :max="p.unallocated" step="0.01" dir="ltr" class="field-input text-start" />
                </Field>
                <Field label="من حساب" id="rf-acc" :error="refundForm.errors.account_id" required>
                    <select id="rf-acc" v-model="refundForm.account_id" class="field-input"><option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option></select>
                </Field>
                <Field label="التاريخ" id="rf-date" :error="refundForm.errors.date" required><input id="rf-date" v-model="refundForm.date" type="date" :max="today" dir="ltr" class="field-input" /></Field>
                <Field label="الطريقة" id="rf-m" :error="refundForm.errors.method"><select id="rf-m" v-model="refundForm.method" class="field-input"><option v-for="(l, k) in methods" :key="k" :value="k">{{ l }}</option></select></Field>
                <Field label="المرجع" id="rf-ref"><input id="rf-ref" v-model="refundForm.reference" dir="ltr" class="field-input text-start" /></Field>
                <Field label="البيان" id="rf-notes"><input id="rf-notes" v-model="refundForm.notes" class="field-input" /></Field>
            </div>
            <template #footer><Btn variant="secondary" @click="refunding = false">إلغاء</Btn><Btn :loading="refundForm.processing" @click="refund">تسجيل الرد</Btn></template>
        </Modal>

        <Modal v-model="reversing" title="عكس السند">
            <Field label="السبب" id="rv" :error="reverseForm.errors.reason" required><textarea id="rv" v-model="reverseForm.reason" rows="3" class="field-input" placeholder="مثال: سُجّل مرتين" /></Field>
            <p class="mt-3 text-xs text-slate-500">يبقى رقم السند في السجل بحالة «معكوس»، ويخرج من رصيد الحساب، وتُفك تسوياته فتعود الفواتير مفتوحة.</p>
            <template #footer><Btn variant="secondary" @click="reversing = false">تراجع</Btn><Btn variant="danger" :loading="reverseForm.processing" @click="reverse">عكس السند</Btn></template>
        </Modal>
    </AppLayout>
</template>

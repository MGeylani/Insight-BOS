<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Pencil, Send, FileDown, Eye, Ban, Copy, Trash2, Briefcase, Building2, FileSignature, FileText, UserRound, CalendarDays, AlertTriangle, Timer, HandCoins, Plus, Link2 } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import FinanceTabs from '@/components/finance/FinanceTabs.vue';
import AttachmentsPanel from '@/components/AttachmentsPanel.vue';
import { useAuth } from '@/lib/auth';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { money } from '@/lib/crm';
import { invoiceStatusTone, hours } from '@/lib/finance';

const props = defineProps({ invoice: Object, log: Array, statuses: Object, files: Object, payments: { type: Array, default: () => [] }, credits: { type: Array, default: () => [] }, accounts: Array, methods: Object });
const { can } = useAuth();
const page = usePage();
const inv = computed(() => props.invoice);
const errors = computed(() => Object.values(page.props.errors ?? {}));
const hasDiscount = computed(() => inv.value.items.some((i) => i.discount_percent > 0));
const hasTax = computed(() => inv.value.items.some((i) => i.tax_rate > 0));

const busy = ref(false);
const post = (action, msg) => {
    if (msg && !confirm(msg)) return;
    router.post(`/finance/invoices/${inv.value.id}/${action}`, {}, { preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false) });
};
const issue = () => post('issue', 'إصدار الفاتورة؟ ستُمنح رقماً وتُقفل للتعديل، ويُثبَّت سعر الصرف والضريبة والإجمالي.');
const duplicate = () => post('duplicate');
const destroy = () => confirm('حذف هذه المسودة؟ تعود دفعات العقد والساعات المرتبطة قابلة للفوترة.') && router.delete(`/finance/invoices/${inv.value.id}`);

const cancelling = ref(false);
const cancelForm = useForm({ reason: '' });
const cancel = () => cancelForm.post(`/finance/invoices/${inv.value.id}/cancel`, { preserveScroll: true, onSuccess: () => { cancelling.value = false; cancelForm.reset(); } });

// Settle the balance from the client's earlier unallocated receipts (credit).
const applying = ref(null);
const creditForm = useForm({ invoice_id: props.invoice.id, amount: '', payment_amount: '' });
const openCredit = (c) => {
    applying.value = c;
    creditForm.amount = c.currency === inv.value.currency ? Math.min(c.unallocated, inv.value.balance) : inv.value.balance;
    creditForm.payment_amount = '';
};
const applyCredit = () => creditForm.transform((d) => ({ ...d, payment_amount: d.payment_amount || null }))
    .post(`/finance/payments/${applying.value.id}/allocate`, { preserveScroll: true, onSuccess: () => (applying.value = null) });
const canReceive = computed(() => inv.value.status !== 'draft' && inv.value.status !== 'cancelled' && inv.value.balance > 0 && can('finance.create'));

const pdfUrl = computed(() => `/finance/invoices/${inv.value.id}/pdf`);
</script>

<template>
    <AppLayout :title="inv.number ?? inv.title">
        <PageHeader :title="inv.title" :subtitle="inv.number ? `فاتورة ${inv.number}` : 'مسودة فاتورة'">
            <template #actions>
                <template v-if="inv.status === 'draft' && inv.can_edit">
                    <Btn variant="secondary" :href="`/finance/invoices/${inv.id}/edit`"><Pencil class="size-4" /> تعديل</Btn>
                    <a :href="pdfUrl" target="_blank" class="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"><Eye class="size-4" /> معاينة</a>
                    <Btn :loading="busy" :disabled="!inv.items.length" @click="issue"><Send class="size-4" /> إصدار</Btn>
                </template>
                <template v-else>
                    <a :href="`${pdfUrl}?download=1`" class="inline-flex items-center gap-2 rounded-xl bg-navy px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-navy-700"><FileDown class="size-4" /> تنزيل PDF</a>
                    <a :href="pdfUrl" target="_blank" class="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"><Eye class="size-4" /> عرض</a>
                </template>
                <Btn v-if="inv.can_cancel" variant="ghost" class="!text-red-600" title="إلغاء الفاتورة" @click="cancelling = true"><Ban class="size-4" /> إلغاء</Btn>
                <Btn v-if="inv.status !== 'draft' && can('finance.create')" variant="ghost" @click="duplicate"><Copy class="size-4" /> نسخة جديدة</Btn>
                <Btn v-if="inv.can_delete" variant="ghost" title="حذف المسودة" @click="destroy"><Trash2 class="size-4" /></Btn>
            </template>
        </PageHeader>
        <FinanceTabs />

        <div v-if="errors.length" class="mb-6 rounded-2xl bg-red-50 p-4 text-sm text-red-800"><p v-for="e in errors" :key="e">{{ e }}</p></div>
        <div v-else-if="inv.missing_rate" class="mb-6 flex items-center gap-2 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900">
            <AlertTriangle class="size-4 shrink-0" /> لا يوجد سعر صرف لعملة {{ inv.currency }}. أدخل سعر اليوم من <a href="/settings/currencies" class="font-semibold underline">العملات وأسعار الصرف</a> قبل الإصدار.
        </div>
        <div v-if="inv.status === 'cancelled'" class="mb-6 rounded-2xl bg-slate-100 p-4 text-sm text-slate-700">
            <strong>ملغاة</strong> في {{ formatDate(inv.cancelled_at) }}<template v-if="inv.cancel_reason"> — {{ inv.cancel_reason }}</template>
        </div>
        <div v-if="inv.status === 'overdue'" class="mb-6 flex items-center gap-2 rounded-2xl bg-red-50 p-4 text-sm text-red-900">
            <AlertTriangle class="size-4 shrink-0" /> متأخرة منذ {{ formatDate(inv.due_date) }}، والمتبقي {{ money(inv.balance, inv.currency) }}.
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="card overflow-hidden xl:col-span-2">
                <div class="brand-gradient h-1.5"></div>
                <div class="p-6">
                    <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="text-xs text-slate-500">فاتورة إلى</p>
                            <p class="text-lg font-bold text-navy">{{ inv.client?.name }}</p>
                            <p v-if="inv.contact" class="text-sm text-slate-600">عناية: {{ inv.contact.name }}</p>
                        </div>
                        <Badge :tone="invoiceStatusTone[inv.status]">{{ statuses[inv.status] }}</Badge>
                    </div>
                    <p v-if="inv.intro" class="mb-5 whitespace-pre-line text-sm leading-relaxed text-slate-700">{{ inv.intro }}</p>

                    <div class="overflow-x-auto">
                        <table class="tbl">
                            <thead><tr><th class="w-8">#</th><th>البند</th><th>الكمية</th><th>سعر الوحدة</th><th v-if="hasDiscount">الخصم</th><th v-if="hasTax">الضريبة</th><th>المجموع</th></tr></thead>
                            <tbody>
                                <tr v-for="(it, i) in inv.items" :key="it.id">
                                    <td class="num text-slate-400">{{ i + 1 }}</td>
                                    <td>
                                        <p class="font-medium text-slate-800">{{ it.name }}</p>
                                        <p v-if="it.details" class="whitespace-pre-line text-xs text-slate-500">{{ it.details }}</p>
                                        <p v-if="it.schedule" class="mt-0.5 inline-flex items-center gap-1 text-[11px] text-plum"><FileSignature class="size-3" /> دفعة عقد</p>
                                        <p v-if="it.minutes" class="mt-0.5 inline-flex items-center gap-1 text-[11px] text-brand"><Timer class="size-3" /> <span class="num">{{ formatNumber(hours(it.minutes)) }}</span> ساعة مسجّلة</p>
                                    </td>
                                    <td class="whitespace-nowrap"><span class="num">{{ formatNumber(it.quantity) }}</span> <span class="text-xs text-slate-500">{{ it.unit }}</span></td>
                                    <td class="num">{{ formatNumber(it.unit_price) }}</td>
                                    <td v-if="hasDiscount" class="num">{{ it.discount_percent ? formatNumber(it.discount_percent) + '%' : '—' }}</td>
                                    <td v-if="hasTax" class="num">{{ it.tax_rate ? formatNumber(it.tax_rate) + '%' : '—' }}</td>
                                    <td class="num font-semibold">{{ formatNumber(it.net) }}</td>
                                </tr>
                                <tr v-if="!inv.items.length"><td colspan="7" class="py-8 text-center text-slate-500">لا توجد بنود بعد.</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <dl class="ms-auto mt-4 max-w-xs space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">المجموع</dt><dd class="num">{{ formatNumber(inv.totals.subtotal) }}</dd></div>
                        <div v-if="inv.totals.discount" class="flex justify-between"><dt class="text-slate-500">الخصم</dt><dd class="num text-red-600">- {{ formatNumber(inv.totals.discount) }}</dd></div>
                        <div v-if="inv.totals.tax" class="flex justify-between"><dt class="text-slate-500">الضريبة</dt><dd class="num">{{ formatNumber(inv.totals.tax) }}</dd></div>
                        <div class="flex justify-between rounded-xl bg-navy px-3 py-2 font-bold text-white"><dt>الإجمالي</dt><dd class="num">{{ money(inv.totals.total, inv.currency) }}</dd></div>
                        <template v-if="inv.paid > 0">
                            <div class="flex justify-between"><dt class="text-slate-500">المدفوع</dt><dd class="num text-emerald-700">- {{ formatNumber(inv.paid) }}</dd></div>
                            <div class="flex justify-between font-bold text-navy"><dt>المتبقي</dt><dd class="num">{{ money(inv.balance, inv.currency) }}</dd></div>
                        </template>
                    </dl>

                    <div v-if="inv.payment_details" class="mt-6 border-t border-slate-100 pt-4 text-sm"><p class="mb-1 font-semibold text-navy">طريقة الدفع</p><p class="whitespace-pre-line text-slate-700">{{ inv.payment_details }}</p></div>
                    <div v-if="inv.terms" class="mt-4 text-sm"><p class="mb-1 font-semibold text-navy">الشروط</p><p class="whitespace-pre-line text-slate-700">{{ inv.terms }}</p></div>
                    <div v-if="inv.notes" class="mt-4 text-sm"><p class="mb-1 font-semibold text-navy">ملاحظات</p><p class="whitespace-pre-line text-slate-700">{{ inv.notes }}</p></div>
                </div>
            </div>

            <div class="space-y-6">
                <div class="card p-5">
                    <dl class="space-y-3 text-sm">
                        <div class="flex items-center gap-2"><Building2 class="size-4 text-slate-400" /><Link :href="`/crm/clients/${inv.client?.id}`" class="text-brand hover:underline">{{ inv.client?.name }}</Link></div>
                        <div v-if="inv.project" class="flex items-center gap-2"><Briefcase class="size-4 text-slate-400" /><Link :href="`/projects/${inv.project.id}`" class="text-brand hover:underline">{{ inv.project.number }} · {{ inv.project.name }}</Link></div>
                        <div v-if="inv.contract" class="flex items-center gap-2"><FileSignature class="size-4 text-slate-400" /><Link :href="`/crm/contracts/${inv.contract.id}`" class="text-brand hover:underline">{{ inv.contract.number ?? 'عقد' }} · {{ inv.contract.title }}</Link></div>
                        <div v-if="inv.quote" class="flex items-center gap-2"><FileText class="size-4 text-slate-400" /><Link :href="`/crm/quotes/${inv.quote.id}`" class="text-brand hover:underline">{{ inv.quote.number }} · {{ inv.quote.title }}</Link></div>
                        <div v-if="inv.contact" class="flex items-center gap-2"><UserRound class="size-4 text-slate-400" />{{ inv.contact.name }} <span v-if="inv.contact.phone" dir="ltr" class="text-xs text-slate-500">{{ inv.contact.phone }}</span></div>
                        <div class="flex items-center gap-2"><UserRound class="size-4 text-slate-400" />المسؤول: {{ inv.owner?.name ?? '—' }}</div>
                        <div class="flex items-center gap-2"><CalendarDays class="size-4 text-slate-400" />الإصدار: {{ formatDate(inv.issue_date) }}</div>
                        <div class="flex items-center gap-2" :class="{ 'font-semibold text-red-600': inv.status === 'overdue' }"><CalendarDays class="size-4 text-slate-400" />الاستحقاق: {{ formatDate(inv.due_date) }}</div>
                        <div v-if="inv.fx_rate && inv.currency !== 'USD'" class="text-xs text-slate-500">سعر الصرف عند الإصدار: <span class="num">1 USD = {{ formatNumber(inv.fx_rate, 4) }} {{ inv.currency }}</span></div>
                        <div v-if="inv.source" class="text-xs text-slate-500">نسخة من <Link :href="`/finance/invoices/${inv.source.id}`" class="text-brand hover:underline">{{ inv.source.number ?? 'مسودة' }}</Link></div>
                    </dl>
                </div>
                <div v-if="inv.status !== 'draft' && inv.status !== 'cancelled'" class="card p-5">
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <h3 class="flex items-center gap-2 font-semibold text-navy"><HandCoins class="size-4" /> الدفعات</h3>
                        <Btn v-if="canReceive" size="sm" :href="`/finance/payments/create?invoice_id=${inv.id}`"><Plus class="size-3.5" /> تسجيل دفعة</Btn>
                    </div>
                    <ul v-if="payments.length" class="divide-y divide-slate-100 text-sm">
                        <li v-for="a in payments" :key="a.id" class="flex items-center justify-between gap-2 py-2">
                            <div>
                                <Link :href="`/finance/payments/${a.payment.id}`" class="num font-semibold text-brand hover:underline">{{ a.payment.number }}</Link>
                                <p class="text-xs text-slate-500">{{ formatDate(a.payment.date) }} · {{ a.payment.account }}</p>
                            </div>
                            <div class="text-end">
                                <p class="num font-semibold text-emerald-700">{{ money(a.amount, inv.currency) }}</p>
                                <p v-if="a.payment.currency !== inv.currency" class="num text-xs text-slate-500">{{ money(a.payment_amount, a.payment.currency) }}</p>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-slate-500">لم تُسجَّل دفعات بعد.</p>
                    <div v-if="credits.length && inv.balance > 0 && can('finance.edit')" class="mt-3 rounded-xl bg-sky/10 p-3 text-sm">
                        <p class="mb-2 text-slate-700">للعميل رصيد غير مسوّى:</p>
                        <button v-for="c in credits" :key="c.id" type="button" class="flex w-full items-center justify-between rounded-lg px-2 py-1.5 hover:bg-white" @click="openCredit(c)">
                            <span class="num">{{ c.number }} · {{ money(c.unallocated, c.currency) }}</span>
                            <span class="flex items-center gap-1 text-xs font-semibold text-brand"><Link2 class="size-3.5" /> تسوية</span>
                        </button>
                    </div>
                </div>
                <AttachmentsPanel :files="files" title="المرفقات" />
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

        <Modal :model-value="!!applying" title="تسوية من رصيد العميل" @update:model-value="(v) => !v && (applying = null)">
            <template v-if="applying">
                <p class="mb-4 text-sm text-slate-600">من السند <span class="num font-semibold">{{ applying.number }}</span> (غير المسوّى {{ money(applying.unallocated, applying.currency) }}) على المتبقي {{ money(inv.balance, inv.currency) }}.</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <Field :label="`المبلغ على الفاتورة (${inv.currency})`" id="ca" :error="creditForm.errors.amount || creditForm.errors.allocations"><input id="ca" v-model="creditForm.amount" type="number" step="0.01" min="0" dir="ltr" class="field-input" /></Field>
                    <Field v-if="applying.currency !== inv.currency" :label="`يُخصم من السند (${applying.currency})`" id="cp" hint="فارغ = بسعر صرف تاريخ السند"><input id="cp" v-model="creditForm.payment_amount" type="number" step="0.01" min="0" dir="ltr" class="field-input" /></Field>
                </div>
            </template>
            <template #footer><Btn variant="secondary" @click="applying = null">تراجع</Btn><Btn :loading="creditForm.processing" @click="applyCredit">تسوية</Btn></template>
        </Modal>

        <Modal v-model="cancelling" title="إلغاء الفاتورة">
            <Field label="سبب الإلغاء" id="cx" :error="cancelForm.errors.reason" required>
                <textarea id="cx" v-model="cancelForm.reason" rows="3" class="field-input" placeholder="مثال: خطأ في المبلغ، ستصدر فاتورة بديلة" />
            </Field>
            <p class="mt-3 text-xs text-slate-500">يبقى رقم الفاتورة محفوظاً في السجل، وتعود دفعات العقد والساعات المرتبطة بها قابلة للفوترة.</p>
            <template #footer>
                <Btn variant="secondary" @click="cancelling = false">تراجع</Btn>
                <Btn variant="danger" :loading="cancelForm.processing" @click="cancel">إلغاء الفاتورة</Btn>
            </template>
        </Modal>
    </AppLayout>
</template>

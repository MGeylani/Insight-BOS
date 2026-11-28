<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Pencil, Send, FileDown, Eye, CheckCircle2, XCircle, Ban, Copy, Trash2, FileSignature, Briefcase, Building2, Handshake, UserRound, CalendarDays, AlertTriangle } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import CrmTabs from '@/components/crm/CrmTabs.vue';
import ContractList from '@/components/crm/ContractList.vue';
import AttachmentsPanel from '@/components/AttachmentsPanel.vue';
import { useAuth } from '@/lib/auth';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { money, quoteStatusTone } from '@/lib/crm';

const props = defineProps({ quote: Object, copies: Array, log: Array, statuses: Object, oppStages: Object, contracts: Array, contractStatuses: Object, project: Object, files: Object });
const { can } = useAuth();
const page = usePage();
const q = computed(() => props.quote);
const errors = computed(() => Object.values(page.props.errors ?? {}));
const hasDiscount = computed(() => q.value.items.some((i) => i.discount_percent > 0));
const hasTax = computed(() => q.value.items.some((i) => i.tax_rate > 0));

const busy = ref(false);
const post = (action, msg) => {
    if (msg && !confirm(msg)) return;
    router.post(`/crm/quotes/${q.value.id}/${action}`, {}, { preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false) });
};
const issue = () => post('issue', 'إصدار العرض؟ سيُمنح رقماً ويُقفل للتعديل، ويُثبَّت سعر الصرف ونسب الضريبة.');
const accept = () => post('accept', 'تسجيل قبول العميل لهذا العرض؟' + (q.value.opportunity ? ' ستنتقل الفرصة إلى «مكسوبة».' : ''));
const cancel = () => post('cancel', 'إلغاء هذا العرض؟ يبقى رقمه محفوظاً ولا يمكن التراجع.');
const duplicate = () => post('duplicate');
const destroy = () => confirm('حذف هذه المسودة؟') && router.delete(`/crm/quotes/${q.value.id}`);

const rejecting = ref(false);
const rejectForm = useForm({ reason: '' });
const reject = () => rejectForm.post(`/crm/quotes/${q.value.id}/reject`, { preserveScroll: true, onSuccess: () => { rejecting.value = false; rejectForm.reset(); } });

const pdfUrl = computed(() => `/crm/quotes/${q.value.id}/pdf`);
const open = computed(() => ['sent', 'expired'].includes(q.value.status));
</script>

<template>
    <AppLayout :title="q.number ?? q.title">
        <PageHeader :title="q.title" :subtitle="q.number ? `عرض سعر ${q.number}` : 'مسودة عرض سعر'">
            <template #actions>
                <template v-if="q.status === 'draft' && q.can_edit">
                    <Btn variant="secondary" :href="`/crm/quotes/${q.id}/edit`"><Pencil class="size-4" /> تعديل</Btn>
                    <a :href="pdfUrl" target="_blank" class="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"><Eye class="size-4" /> معاينة</a>
                    <Btn :loading="busy" :disabled="!q.items.length" @click="issue"><Send class="size-4" /> إصدار</Btn>
                </template>
                <template v-else>
                    <a :href="`${pdfUrl}?download=1`" class="inline-flex items-center gap-2 rounded-xl bg-navy px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-navy-700"><FileDown class="size-4" /> تنزيل PDF</a>
                    <a :href="pdfUrl" target="_blank" class="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"><Eye class="size-4" /> عرض</a>
                </template>
                <template v-if="open && q.can_edit">
                    <Btn variant="secondary" class="!text-emerald-700" :loading="busy" @click="accept"><CheckCircle2 class="size-4" /> قبول</Btn>
                    <Btn variant="secondary" class="!text-red-600" @click="rejecting = true"><XCircle class="size-4" /> رفض</Btn>
                </template>
                <Btn v-if="(open || q.status === 'rejected') && q.can_edit" variant="ghost" title="إلغاء العرض" @click="cancel"><Ban class="size-4" /></Btn>
                <Btn v-if="['accepted', 'sent', 'expired'].includes(q.status) && can('crm.create')" :variant="q.status === 'accepted' ? 'primary' : 'secondary'" :href="`/crm/contracts/create?quote_id=${q.id}`"><FileSignature class="size-4" /> إنشاء عقد</Btn>
                <Btn v-if="q.status === 'accepted' && !project && can('projects.create')" :href="`/projects/create?quote_id=${q.id}`"><Briefcase class="size-4" /> تحويل إلى مشروع</Btn>
                <Btn v-if="q.status !== 'draft' && can('crm.create')" variant="ghost" title="نسخة جديدة" @click="duplicate"><Copy class="size-4" /> نسخة جديدة</Btn>
                <Btn v-if="q.can_delete" variant="ghost" title="حذف المسودة" @click="destroy"><Trash2 class="size-4" /></Btn>
            </template>
        </PageHeader>
        <CrmTabs />

        <div v-if="errors.length" class="mb-6 rounded-2xl bg-red-50 p-4 text-sm text-red-800">
            <p v-for="e in errors" :key="e">{{ e }}</p>
        </div>
        <div v-else-if="q.missing_rate" class="mb-6 flex items-center gap-2 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900">
            <AlertTriangle class="size-4 shrink-0" /> لا يوجد سعر صرف لعملة {{ q.currency }}. أدخل سعر اليوم من <a href="/settings/currencies" class="font-semibold underline">العملات وأسعار الصرف</a> قبل الإصدار.
        </div>
        <div v-if="q.status === 'rejected'" class="mb-6 rounded-2xl bg-red-50 p-4 text-sm text-red-900">
            <strong>رفضه العميل</strong> في {{ formatDate(q.rejected_at) }}<template v-if="q.rejection_reason"> — {{ q.rejection_reason }}</template>
        </div>
        <div v-if="q.status === 'accepted'" class="mb-6 rounded-2xl bg-emerald-50 p-4 text-sm text-emerald-900">
            <strong>قبله العميل</strong> في {{ formatDate(q.accepted_at) }}.<template v-if="!contracts.length"> أنشئ عقداً منه ليُنسخ العميل والقيمة ونطاق العمل.</template>
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="card overflow-hidden xl:col-span-2">
                <div class="brand-gradient h-1.5"></div>
                <div class="p-6">
                    <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="text-xs text-slate-500">مقدَّم إلى</p>
                            <p class="text-lg font-bold text-navy">{{ q.client?.name }}</p>
                            <p v-if="q.contact" class="text-sm text-slate-600">عناية: {{ q.contact.name }}</p>
                        </div>
                        <Badge :tone="quoteStatusTone[q.status]">{{ statuses[q.status] }}</Badge>
                    </div>
                    <p v-if="q.intro" class="mb-5 whitespace-pre-line text-sm leading-relaxed text-slate-700">{{ q.intro }}</p>

                    <div class="overflow-x-auto">
                        <table class="tbl">
                            <thead><tr><th class="w-8">#</th><th>البند</th><th>الكمية</th><th>سعر الوحدة</th><th v-if="hasDiscount">الخصم</th><th v-if="hasTax">الضريبة</th><th>المجموع</th></tr></thead>
                            <tbody>
                                <tr v-for="(it, i) in q.items" :key="it.id">
                                    <td class="num text-slate-400">{{ i + 1 }}</td>
                                    <td><p class="font-medium text-slate-800">{{ it.name }}</p><p v-if="it.details" class="whitespace-pre-line text-xs text-slate-500">{{ it.details }}</p></td>
                                    <td class="whitespace-nowrap"><span class="num">{{ formatNumber(it.quantity) }}</span> <span class="text-xs text-slate-500">{{ it.unit }}</span></td>
                                    <td class="num">{{ formatNumber(it.unit_price) }}</td>
                                    <td v-if="hasDiscount" class="num">{{ it.discount_percent ? formatNumber(it.discount_percent) + '%' : '—' }}</td>
                                    <td v-if="hasTax" class="num">{{ it.tax_rate ? formatNumber(it.tax_rate) + '%' : '—' }}</td>
                                    <td class="num font-semibold">{{ formatNumber(it.net) }}</td>
                                </tr>
                                <tr v-if="!q.items.length"><td colspan="7" class="py-8 text-center text-slate-500">لا توجد بنود بعد.</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <dl class="ms-auto mt-4 max-w-xs space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">المجموع</dt><dd class="num">{{ formatNumber(q.totals.subtotal) }}</dd></div>
                        <div v-if="q.totals.discount" class="flex justify-between"><dt class="text-slate-500">الخصم</dt><dd class="num text-red-600">- {{ formatNumber(q.totals.discount) }}</dd></div>
                        <div v-if="q.totals.tax" class="flex justify-between"><dt class="text-slate-500">الضريبة</dt><dd class="num">{{ formatNumber(q.totals.tax) }}</dd></div>
                        <div class="flex justify-between rounded-xl bg-navy px-3 py-2 font-bold text-white"><dt>الإجمالي</dt><dd class="num">{{ money(q.totals.total, q.currency) }}</dd></div>
                    </dl>

                    <div v-if="q.terms" class="mt-6 border-t border-slate-100 pt-4 text-sm"><p class="mb-1 font-semibold text-navy">الشروط والأحكام</p><p class="whitespace-pre-line text-slate-700">{{ q.terms }}</p></div>
                    <div v-if="q.notes" class="mt-4 text-sm"><p class="mb-1 font-semibold text-navy">ملاحظات</p><p class="whitespace-pre-line text-slate-700">{{ q.notes }}</p></div>
                </div>
            </div>

            <div class="space-y-6">
                <div class="card p-5">
                    <dl class="space-y-3 text-sm">
                        <div class="flex items-center gap-2"><Building2 class="size-4 text-slate-400" /><Link :href="`/crm/clients/${q.client?.id}`" class="text-brand hover:underline">{{ q.client?.name }}</Link></div>
                        <div v-if="q.opportunity" class="flex items-center gap-2"><Handshake class="size-4 text-slate-400" /><Link :href="`/crm/opportunities/${q.opportunity.id}`" class="text-brand hover:underline">{{ q.opportunity.title }}</Link> <Badge>{{ oppStages[q.opportunity.stage] }}</Badge></div>
                        <div v-if="q.contact" class="flex items-center gap-2"><UserRound class="size-4 text-slate-400" />{{ q.contact.name }} <span v-if="q.contact.phone" dir="ltr" class="text-xs text-slate-500">{{ q.contact.phone }}</span></div>
                        <div class="flex items-center gap-2"><UserRound class="size-4 text-slate-400" />المسؤول: {{ q.owner?.name ?? '—' }}</div>
                        <div class="flex items-center gap-2"><CalendarDays class="size-4 text-slate-400" />الإصدار: {{ formatDate(q.issue_date) }}</div>
                        <div class="flex items-center gap-2" :class="{ 'text-amber-700': q.status === 'expired' }"><CalendarDays class="size-4 text-slate-400" />صالح حتى: {{ formatDate(q.valid_until) }}</div>
                        <div v-if="q.fx_rate && q.currency !== 'USD'" class="text-xs text-slate-500">سعر الصرف عند الإصدار: <span class="num">1 USD = {{ formatNumber(q.fx_rate, 4) }} {{ q.currency }}</span></div>
                        <div v-if="q.source" class="text-xs text-slate-500">نسخة من <Link :href="`/crm/quotes/${q.source.id}`" class="text-brand hover:underline">{{ q.source.number ?? 'مسودة' }}</Link></div>
                        <div v-for="c in copies" :key="c.id" class="text-xs text-slate-500">نسخة لاحقة: <Link :href="`/crm/quotes/${c.id}`" class="text-brand hover:underline">{{ c.number ?? 'مسودة' }}</Link> ({{ statuses[c.status] }})</div>
                    </dl>
                </div>
                <div v-if="project" class="card flex items-center gap-3 p-5 text-sm">
                    <Briefcase class="size-4 text-slate-400" />
                    <span class="text-slate-500">المشروع:</span>
                    <Link v-if="project.can_open" :href="`/projects/${project.id}`" class="font-medium text-brand hover:underline">{{ project.number }} · {{ project.name }}</Link>
                    <span v-else class="font-medium text-slate-700">{{ project.number }} · {{ project.name }}</span>
                </div>
                <ContractList v-if="contracts.length" :contracts="contracts" :statuses="contractStatuses" />
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

        <Modal v-model="rejecting" title="تسجيل رفض العرض">
            <Field label="سبب الرفض" id="rj" :error="rejectForm.errors.reason" required>
                <textarea id="rj" v-model="rejectForm.reason" rows="3" class="field-input" placeholder="مثال: السعر أعلى من ميزانيتهم" />
            </Field>
            <p class="mt-3 text-xs text-slate-500">الفرصة المرتبطة لا تتغير تلقائياً؛ انقلها إلى «خاسرة» مع السبب إن انتهت.</p>
            <template #footer>
                <Btn variant="secondary" @click="rejecting = false">إلغاء</Btn>
                <Btn variant="danger" :loading="rejectForm.processing" @click="reject">تسجيل الرفض</Btn>
            </template>
        </Modal>
    </AppLayout>
</template>

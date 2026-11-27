<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Pencil, Send, FileDown, Eye, PenLine, Ban, Copy, Trash2, Building2, Handshake, UserRound, CalendarDays, AlertTriangle,
    RefreshCw, CheckCheck, XOctagon, FileText, Wallet, Flag, CheckCircle2, Circle, Briefcase,
} from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import CrmTabs from '@/components/crm/CrmTabs.vue';
import { useAuth } from '@/lib/auth';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { money, contractStatusTone, scheduleStatusTone } from '@/lib/crm';

const props = defineProps({
    contract: Object, copies: Array, log: Array, statuses: Object, types: Object,
    scheduleStatuses: Object, billingTimings: Object, oppStages: Object, project: Object,
});
const { can } = useAuth();
const page = usePage();
const c = computed(() => props.contract);
const errors = computed(() => Object.values(page.props.errors ?? {}));
const retainer = computed(() => c.value.type === 'retainer');
const running = computed(() => ['active', 'expired'].includes(c.value.status));
const payments = computed(() => c.value.schedule.filter((s) => s.kind === 'payment'));
const milestones = computed(() => c.value.schedule.filter((s) => s.kind === 'milestone'));
const pendingTotal = computed(() => payments.value.filter((p) => ['pending', 'overdue'].includes(p.status)).reduce((s, p) => s + p.amount, 0));
const today = new Date().toISOString().slice(0, 10);

const busy = ref(false);
const post = (action, msg, data = {}) => {
    if (msg && !confirm(msg)) return;
    router.post(`/crm/contracts/${c.value.id}/${action}`, data, { preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false) });
};
const issue = () => post('issue', 'إصدار العقد للتوقيع؟ سيُمنح رقماً ويُقفل للتعديل، ويُثبَّت سعر الصرف.');
const cancel = () => post('cancel', 'إلغاء هذا العقد غير الموقّع؟ يبقى رقمه محفوظاً ولا يمكن التراجع.');
const complete = () => post('complete', 'إنهاء العقد على أنه اكتمل؟');
const renew = () => post('renew', `تجديد العقد ${c.value.term_months} شهراً إضافية بعد ${formatDate(c.value.end_date)}؟`);
const duplicate = () => post('duplicate');
const toggleMilestone = (row) => router.post(`/crm/contracts/${c.value.id}/schedule/${row.id}`, { done: row.status !== 'done' }, { preserveScroll: true });
const destroy = () => confirm('حذف هذه المسودة؟') && router.delete(`/crm/contracts/${c.value.id}`);

const signing = ref(false);
const signForm = useForm({ signed_on: today, client_signatory: c.value.contact?.name ?? '' });
const sign = () => signForm.post(`/crm/contracts/${c.value.id}/sign`, { preserveScroll: true, onSuccess: () => (signing.value = false) });

const terminating = ref(false);
const termForm = useForm({ terminated_on: today, reason: '' });
const terminate = () => termForm.post(`/crm/contracts/${c.value.id}/terminate`, { preserveScroll: true, onSuccess: () => (terminating.value = false) });

const pdfUrl = computed(() => `/crm/contracts/${c.value.id}/pdf`);
const btnLink = 'inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50';
</script>

<template>
    <AppLayout :title="c.number ?? c.title">
        <PageHeader :title="c.title" :subtitle="(c.number ? `عقد ${c.number}` : 'مسودة عقد') + ' · ' + types[c.type]">
            <template #actions>
                <template v-if="c.status === 'draft' && c.can_edit">
                    <Btn variant="secondary" :href="`/crm/contracts/${c.id}/edit`"><Pencil class="size-4" /> تعديل</Btn>
                    <a :href="pdfUrl" target="_blank" :class="btnLink"><Eye class="size-4" /> معاينة</a>
                    <Btn :loading="busy" @click="issue"><Send class="size-4" /> إصدار للتوقيع</Btn>
                </template>
                <template v-else-if="c.status !== 'draft'">
                    <a :href="`${pdfUrl}?download=1`" class="inline-flex items-center gap-2 rounded-xl bg-navy px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-navy-700"><FileDown class="size-4" /> تنزيل PDF</a>
                    <a :href="pdfUrl" target="_blank" :class="btnLink"><Eye class="size-4" /> عرض</a>
                </template>
                <template v-if="c.status === 'sent' && c.can_edit">
                    <Btn variant="secondary" class="!text-emerald-700" @click="signing = true"><PenLine class="size-4" /> تسجيل التوقيع</Btn>
                    <Btn variant="ghost" title="إلغاء العقد" @click="cancel"><Ban class="size-4" /></Btn>
                </template>
                <template v-if="running && c.can_edit">
                    <Btn v-if="retainer" variant="secondary" :loading="busy" @click="renew"><RefreshCw class="size-4" /> تجديد</Btn>
                    <Btn variant="secondary" :loading="busy" @click="complete"><CheckCheck class="size-4" /> إنهاء</Btn>
                    <Btn variant="ghost" class="!text-red-600" title="فسخ العقد" @click="terminating = true"><XOctagon class="size-4" /> فسخ</Btn>
                </template>
                <Btn v-if="['sent', 'active'].includes(c.status) && !project && can('projects.create')" :variant="c.status === 'active' ? 'primary' : 'secondary'" :href="`/projects/create?contract_id=${c.id}`"><Briefcase class="size-4" /> إنشاء مشروع</Btn>
                <Btn v-if="c.status !== 'draft' && can('crm.create')" variant="ghost" title="نسخة جديدة" @click="duplicate"><Copy class="size-4" /> نسخة جديدة</Btn>
                <Btn v-if="c.can_delete" variant="ghost" title="حذف المسودة" @click="destroy"><Trash2 class="size-4" /></Btn>
            </template>
        </PageHeader>
        <CrmTabs />

        <div v-if="errors.length" class="mb-6 rounded-2xl bg-red-50 p-4 text-sm text-red-800">
            <p v-for="e in errors" :key="e">{{ e }}</p>
        </div>
        <div v-else-if="c.missing_rate" class="mb-6 flex items-center gap-2 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900">
            <AlertTriangle class="size-4 shrink-0" /> لا يوجد سعر صرف لعملة {{ c.currency }}. أدخل سعر اليوم من <a href="/settings/currencies" class="font-semibold underline">العملات وأسعار الصرف</a> قبل الإصدار.
        </div>
        <div v-if="c.status === 'sent'" class="mb-6 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900">
            صدر في {{ formatDate(c.issue_date) }} وينتظر توقيع العميل. بعد التوقيع سجّله هنا ليصبح العقد سارياً.
        </div>
        <div v-if="c.status === 'expired'" class="mb-6 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900">
            انتهت مدة العقد في {{ formatDate(c.end_date) }}. {{ retainer ? 'جدّده لمدة أخرى أو أنهِه.' : 'أنهِه إن اكتمل التسليم.' }}
        </div>
        <div v-if="c.status === 'terminated'" class="mb-6 rounded-2xl bg-red-50 p-4 text-sm text-red-900">
            <strong>فُسخ العقد</strong> في {{ formatDate(c.terminated_on) }} — {{ c.termination_reason }}
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <div class="card overflow-hidden">
                    <div class="brand-gradient h-1.5"></div>
                    <div class="p-6">
                        <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <p class="text-xs text-slate-500">الطرف الثاني</p>
                                <p class="text-lg font-bold text-navy">{{ c.client?.name }}</p>
                                <p v-if="c.contact" class="text-sm text-slate-600">يمثله: {{ c.contact.name }}</p>
                            </div>
                            <Badge :tone="contractStatusTone[c.status]">{{ statuses[c.status] }}</Badge>
                        </div>

                        <dl class="grid gap-4 rounded-2xl bg-slate-50 p-4 text-sm sm:grid-cols-4">
                            <div><dt class="text-xs text-slate-500">{{ retainer ? 'الرسوم الشهرية' : 'قيمة العقد' }}</dt><dd class="num mt-0.5 font-bold text-navy">{{ money(retainer ? c.monthly_fee : c.total, c.currency) }}</dd></div>
                            <div><dt class="text-xs text-slate-500">البدء</dt><dd class="mt-0.5">{{ formatDate(c.start_date) }}</dd></div>
                            <div><dt class="text-xs text-slate-500">الانتهاء</dt><dd class="mt-0.5" :class="{ 'text-amber-700': c.status === 'expired' }">{{ formatDate(c.end_date) }}</dd></div>
                            <div v-if="retainer"><dt class="text-xs text-slate-500">إجمالي الفترة</dt><dd class="num mt-0.5">{{ money(c.total, c.currency) }}</dd></div>
                            <div v-else><dt class="text-xs text-slate-500">متبقٍّ من الدفعات</dt><dd class="num mt-0.5">{{ money(pendingTotal, c.currency) }}</dd></div>
                            <template v-if="retainer">
                                <div><dt class="text-xs text-slate-500">موعد الدفع</dt><dd class="mt-0.5">{{ billingTimings[c.billing_timing] }}</dd></div>
                                <div><dt class="text-xs text-slate-500">المدة</dt><dd class="mt-0.5"><span class="num">{{ c.term_months }}</span> شهراً</dd></div>
                                <div><dt class="text-xs text-slate-500">التجديد</dt><dd class="mt-0.5">{{ c.auto_renew ? 'تلقائي' : 'باتفاق' }}</dd></div>
                            </template>
                            <div v-if="c.notice_days"><dt class="text-xs text-slate-500">مهلة الإخطار</dt><dd class="mt-0.5"><span class="num">{{ c.notice_days }}</span> يوماً</dd></div>
                        </dl>

                        <div v-if="c.scope" class="mt-5 text-sm"><p class="mb-1 font-semibold text-navy">نطاق العمل</p><p class="whitespace-pre-line leading-relaxed text-slate-700">{{ c.scope }}</p></div>
                    </div>
                </div>

                <!-- Payments -->
                <div class="card overflow-hidden">
                    <div class="flex items-center gap-2 border-b border-slate-100 px-5 py-3.5">
                        <Wallet class="size-4 text-emerald-600" /><h3 class="me-auto font-semibold text-navy">جدول الدفعات</h3>
                        <span class="text-xs text-slate-500">تُفوتر الدفعات من موديول المالية (المرحلة 4).</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table v-if="payments.length" class="tbl">
                            <thead><tr><th class="w-8">#</th><th>البيان</th><th>{{ retainer ? 'الفترة' : 'الاستحقاق' }}</th><th>المبلغ</th><th>الحالة</th></tr></thead>
                            <tbody>
                                <tr v-for="(p, i) in payments" :key="p.id" :class="{ 'opacity-50': p.status === 'cancelled' }">
                                    <td class="num text-slate-400">{{ i + 1 }}</td>
                                    <td><p class="font-medium text-slate-800">{{ p.title }}</p><p v-if="p.details" class="whitespace-pre-line text-xs text-slate-500">{{ p.details }}</p></td>
                                    <td class="whitespace-nowrap text-xs text-slate-600">
                                        <template v-if="p.period_start">{{ formatDate(p.period_start) }} ← {{ formatDate(p.period_end) }}<br /><span class="text-slate-400">تستحق {{ formatDate(p.due_date) }}</span></template>
                                        <template v-else>{{ formatDate(p.due_date) }}</template>
                                    </td>
                                    <td class="num whitespace-nowrap font-semibold">{{ formatNumber(p.amount) }} <span v-if="p.percent !== null" class="text-xs font-normal text-slate-400">({{ formatNumber(p.percent, 1) }}%)</span></td>
                                    <td><Badge :tone="scheduleStatusTone[p.status]">{{ p.status === 'done' ? 'مسددة' : scheduleStatuses[p.status] }}</Badge></td>
                                </tr>
                            </tbody>
                            <tfoot><tr class="font-bold text-navy"><td></td><td>المجموع</td><td></td><td class="num">{{ money(c.scheduled_total, c.currency) }}</td><td></td></tr></tfoot>
                        </table>
                        <p v-else class="px-5 py-8 text-center text-sm text-slate-500">لا توجد دفعات.</p>
                    </div>
                </div>

                <!-- Milestones -->
                <div v-if="milestones.length" class="card overflow-hidden">
                    <div class="flex items-center gap-2 border-b border-slate-100 px-5 py-3.5"><Flag class="size-4 text-plum" /><h3 class="font-semibold text-navy">مراحل التسليم</h3></div>
                    <ul class="divide-y divide-slate-100">
                        <li v-for="m in milestones" :key="m.id" class="flex items-start gap-3 px-5 py-3" :class="{ 'opacity-50': m.status === 'cancelled' }">
                            <button type="button" class="mt-0.5 disabled:cursor-default" :disabled="!(running && c.can_edit) || m.status === 'cancelled'" :title="m.status === 'done' ? 'إعادة فتح' : 'تسجيل الإنجاز'" @click="toggleMilestone(m)">
                                <CheckCircle2 v-if="m.status === 'done'" class="size-5 text-emerald-600" />
                                <Circle v-else class="size-5 text-slate-300 hover:text-brand" />
                            </button>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium" :class="m.status === 'done' ? 'text-slate-500 line-through' : 'text-slate-800'">{{ m.title }}</p>
                                <p v-if="m.details" class="whitespace-pre-line text-xs text-slate-500">{{ m.details }}</p>
                                <p v-if="m.done_at" class="text-xs text-emerald-700">أُنجزت {{ formatDate(m.done_at) }}<template v-if="m.done_by"> · {{ m.done_by }}</template></p>
                            </div>
                            <div class="text-end text-xs">
                                <p class="text-slate-600">{{ formatDate(m.due_date) }}</p>
                                <Badge v-if="m.status !== 'done'" :tone="scheduleStatusTone[m.status]">{{ m.status === 'overdue' ? 'متأخرة' : scheduleStatuses[m.status] }}</Badge>
                            </div>
                        </li>
                    </ul>
                </div>

                <div v-if="c.terms || c.notes" class="card space-y-4 p-6 text-sm">
                    <div v-if="c.terms"><p class="mb-1 font-semibold text-navy">الشروط والأحكام</p><p class="whitespace-pre-line leading-relaxed text-slate-700">{{ c.terms }}</p></div>
                    <div v-if="c.notes"><p class="mb-1 font-semibold text-navy">ملاحظات</p><p class="whitespace-pre-line text-slate-700">{{ c.notes }}</p></div>
                </div>
            </div>

            <div class="space-y-6">
                <div class="card p-5">
                    <dl class="space-y-3 text-sm">
                        <div class="flex items-center gap-2"><Building2 class="size-4 text-slate-400" /><Link :href="`/crm/clients/${c.client?.id}`" class="text-brand hover:underline">{{ c.client?.name }}</Link></div>
                        <div v-if="c.opportunity" class="flex items-center gap-2"><Handshake class="size-4 text-slate-400" /><Link :href="`/crm/opportunities/${c.opportunity.id}`" class="text-brand hover:underline">{{ c.opportunity.title }}</Link> <Badge>{{ oppStages[c.opportunity.stage] }}</Badge></div>
                        <div v-if="c.quote" class="flex items-center gap-2"><FileText class="size-4 text-slate-400" /><Link :href="`/crm/quotes/${c.quote.id}`" class="text-brand hover:underline">عرض {{ c.quote.number ?? 'مسودة' }}</Link></div>
                        <div v-if="c.contact" class="flex items-center gap-2"><UserRound class="size-4 text-slate-400" />{{ c.contact.name }} <span v-if="c.contact.phone" dir="ltr" class="text-xs text-slate-500">{{ c.contact.phone }}</span></div>
                        <div class="flex items-center gap-2"><UserRound class="size-4 text-slate-400" />المسؤول: {{ c.owner?.name ?? '—' }}</div>
                        <div class="flex items-center gap-2"><CalendarDays class="size-4 text-slate-400" />الإصدار: {{ formatDate(c.issue_date) }}</div>
                        <div v-if="c.signed_on" class="flex items-center gap-2"><PenLine class="size-4 text-slate-400" />وُقّع {{ formatDate(c.signed_on) }}<template v-if="c.client_signatory"> · {{ c.client_signatory }}</template></div>
                        <div v-if="c.fx_rate && c.currency !== 'USD'" class="text-xs text-slate-500">سعر الصرف عند الإصدار: <span class="num">1 USD = {{ formatNumber(c.fx_rate, 4) }} {{ c.currency }}</span></div>
                        <div v-if="project" class="flex items-center gap-2"><Briefcase class="size-4 text-slate-400" /><Link v-if="project.can_open" :href="`/projects/${project.id}`" class="text-brand hover:underline">{{ project.number }} · {{ project.name }}</Link><span v-else>{{ project.number }} · {{ project.name }}</span></div>
                        <div v-if="c.source" class="text-xs text-slate-500">نسخة من <Link :href="`/crm/contracts/${c.source.id}`" class="text-brand hover:underline">{{ c.source.number ?? 'مسودة' }}</Link></div>
                        <div v-for="x in copies" :key="x.id" class="text-xs text-slate-500">نسخة لاحقة: <Link :href="`/crm/contracts/${x.id}`" class="text-brand hover:underline">{{ x.number ?? 'مسودة' }}</Link> ({{ statuses[x.status] }})</div>
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

        <Modal v-model="signing" title="تسجيل توقيع العقد">
            <div class="space-y-4">
                <Field label="تاريخ التوقيع" id="sg-date" :error="signForm.errors.signed_on" required>
                    <input id="sg-date" v-model="signForm.signed_on" type="date" :max="today" dir="ltr" class="field-input" />
                </Field>
                <Field label="اسم الموقّع عن العميل" id="sg-name" :error="signForm.errors.client_signatory">
                    <input id="sg-name" v-model="signForm.client_signatory" class="field-input" />
                </Field>
                <p class="text-xs text-slate-500">يصبح العقد سارياً{{ c.opportunity ? '، وتنتقل الفرصة إلى «مكسوبة»' : '' }}. احفظ النسخة الموقّعة في ملفات العميل (يُفعَّل ربط التخزين لاحقاً في هذه المرحلة).</p>
            </div>
            <template #footer>
                <Btn variant="secondary" @click="signing = false">إلغاء</Btn>
                <Btn :loading="signForm.processing" @click="sign">تسجيل التوقيع</Btn>
            </template>
        </Modal>

        <Modal v-model="terminating" title="فسخ العقد">
            <div class="space-y-4">
                <Field label="تاريخ الفسخ" id="tm-date" :error="termForm.errors.terminated_on" required>
                    <input id="tm-date" v-model="termForm.terminated_on" type="date" dir="ltr" class="field-input" />
                </Field>
                <Field label="سبب الفسخ" id="tm-reason" :error="termForm.errors.reason" required>
                    <textarea id="tm-reason" v-model="termForm.reason" rows="3" class="field-input" />
                </Field>
                <p class="text-xs text-slate-500">تُلغى الدفعات والمراحل غير المنجزة التي يقع موعدها بعد تاريخ الفسخ.</p>
            </div>
            <template #footer>
                <Btn variant="secondary" @click="terminating = false">تراجع</Btn>
                <Btn variant="danger" :loading="termForm.processing" @click="terminate">فسخ العقد</Btn>
            </template>
        </Modal>
    </AppLayout>
</template>

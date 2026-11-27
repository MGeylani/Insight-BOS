<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2, ChevronUp, ChevronDown, Save, Wallet, Flag, CalendarRange } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Field from '@/components/Field.vue';
import Toggle from '@/components/Toggle.vue';
import CrmTabs from '@/components/crm/CrmTabs.vue';
import { formatDate, formatNumber } from '@/lib/format';
import { money } from '@/lib/crm';

const props = defineProps({
    contract: Object, form: Object, clients: Array, types: Object, billingTimings: Object,
    defaultTerms: Object, users: Array, currencies: Array,
});

const form = useForm({
    ...props.form,
    contact_id: props.form.contact_id ?? '',
    opportunity_id: props.form.opportunity_id ?? '',
    quote_id: props.form.quote_id ?? '',
    owner_id: props.form.owner_id ?? '',
    client_id: props.form.client_id ?? '',
    value: props.form.value ?? '',
    monthly_fee: props.form.monthly_fee ?? '',
    term_months: props.form.term_months ?? 12,
    billing_timing: props.form.billing_timing ?? 'advance',
    notice_days: props.form.notice_days ?? '',
    start_date: props.form.start_date ?? '',
    schedule: props.form.schedule.map((r) => ({ ...r, details: r.details ?? '', due_date: r.due_date ?? '', amount: r.amount ?? '', _key: Math.random() })),
});

const retainer = computed(() => form.type === 'retainer');
const client = computed(() => props.clients.find((c) => c.id === Number(form.client_id)));

watch(() => form.client_id, (v, old) => {
    if (old === undefined || props.contract) return;
    form.contact_id = '';
    if (!client.value?.opportunities.some((o) => o.id === Number(form.opportunity_id))) form.opportunity_id = '';
    if (!client.value?.quotes.some((q) => q.id === Number(form.quote_id))) form.quote_id = '';
    if (client.value) form.currency = client.value.currency || form.currency;
});
watch(() => form.opportunity_id, (v) => {
    const o = client.value?.opportunities.find((x) => x.id === Number(v));
    if (o && !form.title) form.title = o.title;
});
// Switching type: swap in the other type's default terms if the text was untouched.
watch(() => form.type, (type, old) => {
    if (!form.terms || form.terms === props.defaultTerms[old]) form.terms = props.defaultTerms[type] ?? '';
});

// Rows shown in the editor. Retainer payments are generated on the server, so only milestones are typed in.
const rows = computed(() => form.schedule.map((r, i) => ({ r, i })).filter(({ r }) => !retainer.value || r.kind === 'milestone'));
const add = (kind) => form.schedule.push({ _key: Math.random(), kind, title: '', details: '', due_date: '', amount: kind === 'payment' ? '' : null });
const remove = (i) => form.schedule.splice(i, 1);
function move(i, d) {
    const j = i + d;
    if (j < 0 || j >= form.schedule.length) return;
    const [x] = form.schedule.splice(i, 1);
    form.schedule.splice(j, 0, x);
}

const r2 = (v) => Math.round((Number(v) + Number.EPSILON) * 100) / 100;
const value = computed(() => Number(form.value || 0));
const paymentsTotal = computed(() => r2(form.schedule.filter((r) => r.kind === 'payment').reduce((s, r) => s + Number(r.amount || 0), 0)));
const diff = computed(() => r2(value.value - paymentsTotal.value));
const pct = (amount) => (value.value > 0 ? formatNumber((Number(amount || 0) / value.value) * 100, 1) + '%' : '');

/** Replace the payment rows by a split of the contract value; the last row absorbs rounding. */
function preset(parts, titles) {
    const milestones = form.schedule.filter((r) => r.kind !== 'payment');
    let left = value.value;
    const payments = parts.map((p, i) => {
        const amount = i === parts.length - 1 ? r2(left) : r2((value.value * p) / 100);
        left -= amount;
        return { _key: Math.random(), kind: 'payment', title: titles[i], details: '', due_date: i === 0 ? form.start_date : '', amount };
    });
    form.schedule = [...payments, ...milestones];
}
const presets = [
    { label: 'دفعة واحدة', parts: [100], titles: ['الدفعة الكاملة عند التوقيع'] },
    { label: '50 / 50', parts: [50, 50], titles: ['دفعة مقدمة عند التوقيع', 'الدفعة النهائية عند التسليم'] },
    { label: '40 / 30 / 30', parts: [40, 30, 30], titles: ['دفعة مقدمة عند التوقيع', 'دفعة عند اعتماد المرحلة الأولى', 'الدفعة النهائية عند التسليم'] },
];

// Same calendar rule as the server: months never overflow (31 Jan + 1 month = end of Feb).
function addMonths(iso, n) {
    const [y, m, d] = iso.split('-').map(Number);
    const total = m - 1 + n;
    const ty = y + Math.floor(total / 12);
    const tm = ((total % 12) + 12) % 12;
    const last = new Date(Date.UTC(ty, tm + 1, 0)).getUTCDate();
    return new Date(Date.UTC(ty, tm, Math.min(d, last)));
}
const endDate = computed(() => {
    if (!retainer.value || !form.start_date || !form.term_months) return null;
    const e = addMonths(form.start_date, Number(form.term_months));
    e.setUTCDate(e.getUTCDate() - 1);
    return e.toISOString().slice(0, 10);
});
const retainerTotal = computed(() => r2(Number(form.monthly_fee || 0) * Number(form.term_months || 0)));
const total = computed(() => (retainer.value ? retainerTotal.value : value.value));

const rowError = (i, f) => form.errors[`schedule.${i}.${f}`];

function save() {
    form.transform((d) => ({
        ...d,
        contact_id: d.contact_id || null,
        opportunity_id: d.opportunity_id || null,
        quote_id: d.quote_id || null,
        owner_id: d.owner_id || null,
        start_date: d.start_date || null,
        value: d.value === '' ? null : d.value,
        monthly_fee: d.monthly_fee === '' ? null : d.monthly_fee,
        notice_days: d.notice_days === '' ? null : d.notice_days,
        schedule: d.schedule
            .filter((r) => d.type !== 'retainer' || r.kind === 'milestone')
            .map(({ _key, ...r }) => ({ ...r, due_date: r.due_date || null, amount: r.kind === 'payment' ? r.amount : null })),
    }));
    props.contract ? form.put(`/crm/contracts/${props.contract.id}`) : form.post('/crm/contracts');
}
const heading = computed(() => (props.contract ? 'تعديل مسودة العقد' : 'عقد جديد'));
</script>

<template>
    <AppLayout :title="heading">
        <PageHeader :title="heading" subtitle="المسودة بلا رقم. يُمنح الرقم ويُثبَّت سعر الصرف عند الإصدار للتوقيع.">
            <template #actions>
                <Btn variant="secondary" :href="contract ? `/crm/contracts/${contract.id}` : '/crm/contracts'">إلغاء</Btn>
                <Btn :loading="form.processing" @click="save"><Save class="size-4" /> حفظ المسودة</Btn>
            </template>
        </PageHeader>
        <CrmTabs />

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <!-- Type -->
                <div class="card grid grid-cols-2 gap-1 p-1">
                    <button v-for="(label, k) in types" :key="k" type="button" class="rounded-xl px-4 py-3 text-sm transition"
                        :class="form.type === k ? 'bg-navy font-semibold text-white' : 'text-slate-600 hover:bg-slate-100'" @click="form.type = k">
                        {{ label }}
                        <span class="block text-xs font-normal opacity-75">{{ k === 'project' ? 'قيمة إجمالية وجدول دفعات ومراحل' : 'رسوم شهرية ثابتة لمدة محددة' }}</span>
                    </button>
                </div>

                <!-- Parties -->
                <div class="card grid gap-4 p-5 sm:grid-cols-2">
                    <Field label="العميل" id="k-client" :error="form.errors.client_id" required>
                        <select id="k-client" v-model="form.client_id" class="field-input" :disabled="!!contract">
                            <option value="">اختر العميل…</option>
                            <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </Field>
                    <Field label="جهة الاتصال (ممثل العميل)" id="k-contact" :error="form.errors.contact_id">
                        <select id="k-contact" v-model="form.contact_id" class="field-input" :disabled="!client">
                            <option value="">—</option>
                            <option v-for="c in client?.contacts ?? []" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </Field>
                    <Field label="عنوان العقد" id="k-title" :error="form.errors.title" required class="sm:col-span-2">
                        <input id="k-title" v-model="form.title" class="field-input" placeholder="مثال: إدارة منصات التواصل الاجتماعي" />
                    </Field>
                    <Field label="الفرصة" id="k-opp" :error="form.errors.opportunity_id" hint="عند توقيع العقد تنتقل الفرصة إلى «مكسوبة».">
                        <select id="k-opp" v-model="form.opportunity_id" class="field-input" :disabled="!client">
                            <option value="">بلا فرصة</option>
                            <option v-for="o in client?.opportunities ?? []" :key="o.id" :value="o.id">{{ o.title }}</option>
                        </select>
                    </Field>
                    <Field label="عرض السعر" id="k-quote" :error="form.errors.quote_id">
                        <select id="k-quote" v-model="form.quote_id" class="field-input" :disabled="!client">
                            <option value="">بلا عرض</option>
                            <option v-for="q in client?.quotes ?? []" :key="q.id" :value="q.id">{{ q.number }} — {{ q.title }}</option>
                        </select>
                    </Field>
                    <Field label="المسؤول" id="k-owner" :error="form.errors.owner_id">
                        <select id="k-owner" v-model="form.owner_id" class="field-input"><option value="">أنا</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select>
                    </Field>
                    <Field label="العملة" id="k-cur" :error="form.errors.currency" required>
                        <select id="k-cur" v-model="form.currency" class="field-input"><option v-for="c in currencies" :key="c">{{ c }}</option></select>
                    </Field>
                </div>

                <!-- Term & money -->
                <div class="card grid gap-4 p-5 sm:grid-cols-3">
                    <Field label="تاريخ البدء" id="k-start" :error="form.errors.start_date">
                        <input id="k-start" v-model="form.start_date" type="date" dir="ltr" class="field-input" />
                    </Field>
                    <template v-if="!retainer">
                        <Field label="قيمة العقد" id="k-value" :error="form.errors.value">
                            <input id="k-value" v-model="form.value" type="number" min="0" step="0.01" dir="ltr" class="field-input text-start" />
                        </Field>
                        <Field label="مهلة الإخطار بالفسخ (يوم)" id="k-notice" :error="form.errors.notice_days">
                            <input id="k-notice" v-model="form.notice_days" type="number" min="0" max="365" dir="ltr" class="field-input text-start" />
                        </Field>
                    </template>
                    <template v-else>
                        <Field label="الرسوم الشهرية" id="k-fee" :error="form.errors.monthly_fee">
                            <input id="k-fee" v-model="form.monthly_fee" type="number" min="0" step="0.01" dir="ltr" class="field-input text-start" />
                        </Field>
                        <Field label="المدة (شهر)" id="k-term" :error="form.errors.term_months" :hint="endDate ? `ينتهي في ${formatDate(endDate)}` : null">
                            <input id="k-term" v-model="form.term_months" type="number" min="1" max="60" dir="ltr" class="field-input text-start" />
                        </Field>
                        <Field label="موعد الدفع" id="k-billing" :error="form.errors.billing_timing">
                            <select id="k-billing" v-model="form.billing_timing" class="field-input"><option v-for="(l, k) in billingTimings" :key="k" :value="k">{{ l }}</option></select>
                        </Field>
                        <Field label="مهلة الإخطار (يوم)" id="k-notice" :error="form.errors.notice_days">
                            <input id="k-notice" v-model="form.notice_days" type="number" min="0" max="365" dir="ltr" class="field-input text-start" />
                        </Field>
                        <div class="flex items-end pb-2"><Toggle v-model="form.auto_renew" label="يتجدد تلقائياً" /></div>
                    </template>
                </div>

                <!-- Schedule -->
                <div class="card overflow-hidden">
                    <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 p-4">
                        <h3 class="me-auto font-semibold text-navy">{{ retainer ? 'مراحل وتسليمات (اختياري)' : 'جدول الدفعات والمراحل' }}</h3>
                        <template v-if="!retainer">
                            <span class="text-xs text-slate-500">تقسيم سريع:</span>
                            <button v-for="p in presets" :key="p.label" type="button" class="rounded-lg border border-slate-200 px-2.5 py-1 text-xs hover:border-brand hover:text-brand disabled:opacity-40" :disabled="!value" @click="preset(p.parts, p.titles)">{{ p.label }}</button>
                            <Btn variant="secondary" size="sm" @click="add('payment')"><Wallet class="size-4" /> دفعة</Btn>
                        </template>
                        <Btn variant="secondary" size="sm" @click="add('milestone')"><Flag class="size-4" /> مرحلة</Btn>
                    </div>
                    <p v-if="form.errors.schedule" class="field-error px-4 pt-3">{{ form.errors.schedule }}</p>

                    <div v-if="retainer" class="flex items-start gap-3 border-b border-slate-100 bg-sky-50/60 p-4 text-sm text-slate-700">
                        <CalendarRange class="mt-0.5 size-4 shrink-0 text-brand" />
                        <p>تُولَّد الدفعات تلقائياً: <span class="num font-semibold">{{ form.term_months || 0 }}</span> دفعة شهرية بقيمة <span class="num font-semibold">{{ money(form.monthly_fee || 0, form.currency) }}</span>، تستحق {{ form.billing_timing === 'arrears' ? 'في آخر كل شهر من العقد' : 'في أول كل شهر من العقد' }}. أضف هنا مراحل التسليم إن وُجدت.</p>
                    </div>

                    <div v-if="!rows.length" class="px-6 py-10 text-center text-sm text-slate-500">
                        {{ retainer ? 'لا توجد مراحل.' : 'أدخل قيمة العقد ثم اختر تقسيماً سريعاً، أو أضف الدفعات والمراحل يدوياً.' }}
                    </div>

                    <div v-for="{ r, i } in rows" :key="r._key" class="border-b border-slate-100 p-4 last:border-b-0">
                        <div class="flex gap-3">
                            <div class="flex flex-col items-center gap-1 pt-1 text-slate-400">
                                <component :is="r.kind === 'payment' ? Wallet : Flag" class="size-4" :class="r.kind === 'payment' ? 'text-emerald-600' : 'text-plum'" />
                                <button type="button" class="rounded p-0.5 hover:bg-slate-100 hover:text-navy disabled:opacity-30" :disabled="i === 0" @click="move(i, -1)"><ChevronUp class="size-4" /></button>
                                <button type="button" class="rounded p-0.5 hover:bg-slate-100 hover:text-navy disabled:opacity-30" :disabled="i === form.schedule.length - 1" @click="move(i, 1)"><ChevronDown class="size-4" /></button>
                            </div>
                            <div class="grid min-w-0 flex-1 gap-3" :class="r.kind === 'payment' ? 'sm:grid-cols-6' : 'sm:grid-cols-4'">
                                <Field :label="r.kind === 'payment' ? 'الدفعة' : 'المرحلة'" :error="rowError(i, 'title')" :class="r.kind === 'payment' ? 'sm:col-span-3' : 'sm:col-span-3'">
                                    <input v-model="r.title" class="field-input" :placeholder="r.kind === 'payment' ? 'مثال: دفعة مقدمة عند التوقيع' : 'مثال: تسليم الهوية البصرية'" />
                                </Field>
                                <Field label="الموعد" :error="rowError(i, 'due_date')"><input v-model="r.due_date" type="date" dir="ltr" class="field-input" /></Field>
                                <template v-if="r.kind === 'payment'">
                                    <Field label="المبلغ" :error="rowError(i, 'amount')"><input v-model="r.amount" type="number" min="0" step="0.01" dir="ltr" class="field-input text-start" /></Field>
                                    <div><p class="field-label">النسبة</p><p class="num py-2.5 text-sm text-slate-600">{{ pct(r.amount) }}</p></div>
                                </template>
                                <textarea v-model="r.details" rows="1" class="field-input text-sm" :class="r.kind === 'payment' ? 'sm:col-span-6' : 'sm:col-span-4'" placeholder="تفاصيل أو شرط الاستحقاق (اختياري)" />
                            </div>
                            <button type="button" class="self-start rounded-lg p-2 text-slate-400 hover:bg-red-50 hover:text-red-600" title="حذف" @click="remove(i)"><Trash2 class="size-4" /></button>
                        </div>
                    </div>
                </div>

                <div class="card space-y-4 p-5">
                    <Field label="موضوع العقد ونطاق العمل" id="k-scope" :error="form.errors.scope" hint="الخدمات والتسليمات المتفق عليها. يُنسخ من بنود عرض السعر عند إنشاء العقد منه.">
                        <textarea id="k-scope" v-model="form.scope" rows="5" class="field-input" />
                    </Field>
                    <Field label="الشروط والأحكام" id="k-terms" :error="form.errors.terms" hint="القيمة الافتراضية لكل نوع عقد من صفحة بيانات الشركة.">
                        <textarea id="k-terms" v-model="form.terms" rows="8" class="field-input" />
                    </Field>
                    <Field label="ملاحظات" id="k-notes" :error="form.errors.notes">
                        <textarea id="k-notes" v-model="form.notes" rows="2" class="field-input" />
                    </Field>
                </div>
            </div>

            <!-- Summary -->
            <div>
                <div class="card sticky top-24 p-5">
                    <h3 class="mb-4 font-semibold text-navy">الملخص</h3>
                    <dl class="space-y-2.5 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">النوع</dt><dd>{{ types[form.type] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">البدء</dt><dd>{{ formatDate(form.start_date) }}</dd></div>
                        <div v-if="endDate" class="flex justify-between"><dt class="text-slate-500">الانتهاء</dt><dd>{{ formatDate(endDate) }}</dd></div>
                        <template v-if="!retainer">
                            <div class="flex justify-between"><dt class="text-slate-500">مجموع الدفعات</dt><dd class="num">{{ formatNumber(paymentsTotal) }}</dd></div>
                            <div v-if="diff !== 0" class="flex justify-between text-amber-700"><dt>الفرق عن القيمة</dt><dd class="num">{{ formatNumber(diff) }}</dd></div>
                        </template>
                        <div class="flex justify-between border-t border-slate-100 pt-3 text-base font-bold text-navy"><dt>قيمة العقد</dt><dd class="num">{{ money(total, form.currency) }}</dd></div>
                    </dl>
                    <p v-if="!retainer && diff !== 0" class="mt-3 text-xs text-amber-700">يجب أن يساوي مجموع الدفعات قيمة العقد قبل الإصدار.</p>
                    <Btn class="mt-5 w-full" :loading="form.processing" @click="save"><Save class="size-4" /> حفظ المسودة</Btn>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

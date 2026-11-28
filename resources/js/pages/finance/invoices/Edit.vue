<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2, ChevronUp, ChevronDown, Package, Save, FileSignature, Timer } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Field from '@/components/Field.vue';
import Modal from '@/components/Modal.vue';
import FinanceTabs from '@/components/finance/FinanceTabs.vue';
import { formatNumber, formatDate } from '@/lib/format';
import { money } from '@/lib/crm';
import { lineAmounts, sumLines, hours } from '@/lib/finance';

const props = defineProps({
    invoice: Object, form: Object, clients: Array, services: Array, categories: Array,
    units: Array, users: Array, currencies: Array, paymentDays: Number,
});

const blank = (v) => v ?? '';
const form = useForm({
    ...props.form,
    client_id: blank(props.form.client_id), contact_id: blank(props.form.contact_id), project_id: blank(props.form.project_id),
    owner_id: blank(props.form.owner_id), issue_date: blank(props.form.issue_date), due_date: blank(props.form.due_date),
    items: props.form.items.map((i) => ({ time_ids: [], contract_schedule_id: null, ...i, _key: Math.random() })),
});

const client = computed(() => props.clients.find((c) => c.id === Number(form.client_id)));
watch(() => form.client_id, (v, old) => {
    if (old === undefined || props.invoice) return;
    form.contact_id = '';
    form.project_id = '';
    if (client.value && !form.items.length) form.currency = client.value.currency || form.currency;
});

const taxRate = (it) => Number(props.categories.find((c) => c.id === Number(it.service_category_id))?.tax_rate ?? 0);
const servicesByCategory = computed(() =>
    props.categories.map((c) => ({ ...c, services: props.services.filter((s) => s.service_category_id === c.id) })).filter((g) => g.services.length),
);
const picker = ref('');
function addService() {
    const s = props.services.find((x) => x.id === Number(picker.value));
    picker.value = '';
    if (!s) return;
    form.items.push({ _key: Math.random(), service_id: s.id, service_category_id: s.service_category_id, name: s.name, details: s.description ?? '',
        quantity: 1, unit: s.unit ?? '', unit_price: Number(s.price), discount_percent: 0, time_ids: [], contract_schedule_id: null, _currency: s.currency });
}
const addBlank = () => form.items.push({ _key: Math.random(), service_id: null, service_category_id: props.categories[0]?.id ?? null, name: '', details: '',
    quantity: 1, unit: '', unit_price: 0, discount_percent: 0, time_ids: [], contract_schedule_id: null });
const remove = (i) => form.items.splice(i, 1);
function move(i, d) {
    const j = i + d;
    if (j < 0 || j >= form.items.length) return;
    const [x] = form.items.splice(i, 1);
    form.items.splice(j, 0, x);
}
const totals = computed(() => sumLines(form.items, taxRate));
const hasTax = computed(() => form.items.some((it) => taxRate(it) > 0));
const itemError = (i, f) => form.errors[`items.${i}.${f}`];

// ── Contract instalments of this client ──
const schedOpen = ref(false);
const schedRows = ref([]);
const schedLoading = ref(false);
const inForm = (id) => form.items.some((it) => Number(it.contract_schedule_id) === id);
async function openSchedules() {
    schedOpen.value = true;
    schedLoading.value = true;
    const res = await fetch(`/finance/pending-schedules/${form.client_id}${props.invoice ? `?invoice=${props.invoice.id}` : ''}`, { headers: { Accept: 'application/json' } });
    schedRows.value = (await res.json()).rows;
    schedLoading.value = false;
}
function addSchedule(r) {
    if (inForm(r.contract_schedule_id)) return;
    const { contract, currency, due_date, ...line } = r;
    form.items.push({ ...line, _key: Math.random(), time_ids: [], _currency: currency });
}

// ── Approved billable time of the project ──
const timeOpen = ref(false);
const timeGroups = ref([]);
const timeRate = ref(0);
const timePick = ref([]);
const usedTime = computed(() => new Set(form.items.flatMap((it) => it.time_ids ?? [])));
async function openTime() {
    timeOpen.value = true;
    const res = await fetch(`/finance/billable-time/${form.project_id}${props.invoice ? `?invoice=${props.invoice.id}` : ''}`, { headers: { Accept: 'application/json' } });
    timeGroups.value = (await res.json()).groups.filter((g) => !g.time_ids.some((id) => usedTime.value.has(id)));
    timePick.value = timeGroups.value.map((g, i) => i);
}
function addTime() {
    timePick.value.map((i) => timeGroups.value[i]).forEach((g) => form.items.push({
        _key: Math.random(), service_id: null, service_category_id: props.categories[0]?.id ?? null, contract_schedule_id: null,
        name: g.title, details: 'ساعات عمل معتمدة', quantity: hours(g.minutes), unit: 'ساعة', unit_price: Number(timeRate.value) || 0,
        discount_percent: 0, time_ids: g.time_ids,
    }));
    timeOpen.value = false;
}

function save() {
    form.transform((d) => ({
        ...d,
        contact_id: d.contact_id || null, project_id: d.project_id || null, owner_id: d.owner_id || null,
        issue_date: d.issue_date || null, due_date: d.due_date || null,
        items: d.items.map(({ _key, _currency, ...it }) => ({ ...it, service_category_id: it.service_category_id || null })),
    }));
    props.invoice ? form.put(`/finance/invoices/${props.invoice.id}`) : form.post('/finance/invoices');
}
const heading = computed(() => (props.invoice ? 'تعديل مسودة الفاتورة' : 'فاتورة جديدة'));
</script>

<template>
    <AppLayout :title="heading">
        <PageHeader :title="heading" subtitle="المسودة بلا رقم. يُمنح الرقم ويُثبَّت سعر الصرف والضريبة والإجمالي عند الإصدار.">
            <template #actions>
                <Btn variant="secondary" :href="invoice ? `/finance/invoices/${invoice.id}` : '/finance/invoices'">إلغاء</Btn>
                <Btn :loading="form.processing" @click="save"><Save class="size-4" /> حفظ المسودة</Btn>
            </template>
        </PageHeader>
        <FinanceTabs />

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <div class="card grid gap-4 p-5 sm:grid-cols-2">
                    <Field label="العميل" id="i-client" :error="form.errors.client_id" required>
                        <select id="i-client" v-model="form.client_id" class="field-input" :disabled="!!invoice">
                            <option value="">اختر العميل…</option>
                            <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </Field>
                    <Field label="المشروع" id="i-project" :error="form.errors.project_id" hint="لربط الإيراد بربحية المشروع ولإضافة ساعاته القابلة للفوترة.">
                        <select id="i-project" v-model="form.project_id" class="field-input" :disabled="!client">
                            <option value="">بلا مشروع</option>
                            <option v-for="p in client?.projects ?? []" :key="p.id" :value="p.id">{{ p.number }} · {{ p.name }}</option>
                        </select>
                    </Field>
                    <Field label="عنوان الفاتورة" id="i-title" :error="form.errors.title" required class="sm:col-span-2">
                        <input id="i-title" v-model="form.title" class="field-input" placeholder="مثال: إدارة منصات التواصل — ديسمبر" />
                    </Field>
                    <Field label="جهة الاتصال" id="i-contact" :error="form.errors.contact_id">
                        <select id="i-contact" v-model="form.contact_id" class="field-input" :disabled="!client">
                            <option value="">—</option>
                            <option v-for="c in client?.contacts ?? []" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </Field>
                    <Field label="العملة" id="i-cur" :error="form.errors.currency" required>
                        <select id="i-cur" v-model="form.currency" class="field-input"><option v-for="c in currencies" :key="c">{{ c }}</option></select>
                    </Field>
                    <Field label="تاريخ الإصدار" id="i-issue" :error="form.errors.issue_date" hint="يُستخدم اليوم إن تُرك فارغاً أو كان في المستقبل.">
                        <input id="i-issue" v-model="form.issue_date" type="date" dir="ltr" class="field-input" />
                    </Field>
                    <Field label="تاريخ الاستحقاق" id="i-due" :error="form.errors.due_date" :hint="`فارغ = الإصدار + ${paymentDays} يوماً.`">
                        <input id="i-due" v-model="form.due_date" type="date" dir="ltr" class="field-input" />
                    </Field>
                    <Field label="المسؤول" id="i-owner" :error="form.errors.owner_id">
                        <select id="i-owner" v-model="form.owner_id" class="field-input"><option value="">أنا</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select>
                    </Field>
                </div>

                <div class="card overflow-hidden">
                    <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 p-4">
                        <h3 class="me-auto font-semibold text-navy">بنود الفاتورة</h3>
                        <Btn v-if="client" variant="secondary" size="sm" @click="openSchedules"><FileSignature class="size-4" /> من دفعات العقود</Btn>
                        <Btn v-if="form.project_id" variant="secondary" size="sm" @click="openTime"><Timer class="size-4" /> الساعات المعتمدة</Btn>
                        <select v-if="services.length" v-model="picker" class="field-input w-auto max-w-xs" @change="addService">
                            <option value="">+ من كتالوج الخدمات…</option>
                            <optgroup v-for="g in servicesByCategory" :key="g.id" :label="g.name">
                                <option v-for="s in g.services" :key="s.id" :value="s.id">{{ s.name }} — {{ money(s.price, s.currency) }}</option>
                            </optgroup>
                        </select>
                        <Btn variant="secondary" size="sm" @click="addBlank"><Plus class="size-4" /> بند حر</Btn>
                    </div>
                    <p v-if="form.errors.items" class="field-error px-4 pt-3">{{ form.errors.items }}</p>

                    <div v-if="!form.items.length" class="flex flex-col items-center px-6 py-12 text-center text-sm text-slate-500">
                        <Package class="mb-3 size-8 text-slate-300" />
                        أضف بنوداً من دفعات العقود، أو الساعات المعتمدة للمشروع، أو الكتالوج، أو بنداً حراً.
                    </div>

                    <div v-for="(it, i) in form.items" :key="it._key" class="border-b border-slate-100 p-4 last:border-b-0">
                        <div class="flex gap-3">
                            <div class="flex flex-col items-center gap-1 pt-1 text-slate-400">
                                <span class="num text-xs font-semibold">{{ i + 1 }}</span>
                                <button type="button" class="rounded p-0.5 hover:bg-slate-100 hover:text-navy disabled:opacity-30" :disabled="i === 0" @click="move(i, -1)"><ChevronUp class="size-4" /></button>
                                <button type="button" class="rounded p-0.5 hover:bg-slate-100 hover:text-navy disabled:opacity-30" :disabled="i === form.items.length - 1" @click="move(i, 1)"><ChevronDown class="size-4" /></button>
                            </div>
                            <div class="min-w-0 flex-1 space-y-3">
                                <p v-if="it.contract_schedule_id" class="inline-flex items-center gap-1 rounded-full bg-plum/10 px-2 py-0.5 text-xs text-plum"><FileSignature class="size-3" /> دفعة عقد</p>
                                <p v-if="it.time_ids?.length" class="inline-flex items-center gap-1 rounded-full bg-sky/10 px-2 py-0.5 text-xs text-brand"><Timer class="size-3" /> <span class="num">{{ it.time_ids.length }}</span> قيد وقت</p>
                                <div class="grid gap-3 sm:grid-cols-3">
                                    <Field :error="itemError(i, 'name')" class="sm:col-span-2"><input v-model="it.name" class="field-input font-medium" placeholder="اسم البند" /></Field>
                                    <select v-model="it.service_category_id" class="field-input text-sm" title="التصنيف يحدد نسبة الضريبة">
                                        <option :value="null">بلا تصنيف (0%)</option>
                                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }} ({{ formatNumber(c.tax_rate) }}%)</option>
                                    </select>
                                </div>
                                <textarea v-model="it.details" rows="2" class="field-input text-sm" placeholder="تفاصيل تظهر تحت اسم البند (اختياري)" />
                                <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                                    <Field label="الكمية" :error="itemError(i, 'quantity')"><input v-model="it.quantity" type="number" min="0.01" step="0.01" dir="ltr" class="field-input text-start" /></Field>
                                    <Field label="الوحدة" :error="itemError(i, 'unit')">
                                        <input v-model="it.unit" :list="`u-${i}`" class="field-input" />
                                        <datalist :id="`u-${i}`"><option v-for="u in units" :key="u" :value="u" /></datalist>
                                    </Field>
                                    <Field label="سعر الوحدة" :error="itemError(i, 'unit_price')"><input v-model="it.unit_price" type="number" min="0" step="0.01" dir="ltr" class="field-input text-start" /></Field>
                                    <Field label="خصم %" :error="itemError(i, 'discount_percent')"><input v-model="it.discount_percent" type="number" min="0" max="100" step="0.01" dir="ltr" class="field-input text-start" /></Field>
                                    <div class="col-span-2 sm:col-span-1"><p class="field-label">المجموع</p><p class="num py-2.5 font-semibold text-navy">{{ formatNumber(lineAmounts(it, taxRate(it)).net) }}</p></div>
                                </div>
                                <p v-if="it._currency && it._currency !== form.currency" class="text-xs text-amber-700">المبلغ الأصلي بعملة {{ it._currency }}؛ عدّل السعر ليناسب عملة الفاتورة ({{ form.currency }}).</p>
                            </div>
                            <button type="button" class="self-start rounded-lg p-2 text-slate-400 hover:bg-red-50 hover:text-red-600" title="حذف البند" @click="remove(i)"><Trash2 class="size-4" /></button>
                        </div>
                    </div>
                </div>

                <div class="card space-y-4 p-5">
                    <Field label="مقدمة" id="i-intro" :error="form.errors.intro"><textarea id="i-intro" v-model="form.intro" rows="2" class="field-input" /></Field>
                    <Field label="طريقة الدفع" id="i-pay" :error="form.errors.payment_details" hint="تظهر في الفاتورة (البنك، رقم الحساب…). الافتراضي من بيانات الشركة.">
                        <textarea id="i-pay" v-model="form.payment_details" rows="3" class="field-input" />
                    </Field>
                    <Field label="الشروط" id="i-terms" :error="form.errors.terms"><textarea id="i-terms" v-model="form.terms" rows="3" class="field-input" /></Field>
                    <Field label="ملاحظات للعميل" id="i-notes" :error="form.errors.notes"><textarea id="i-notes" v-model="form.notes" rows="2" class="field-input" /></Field>
                </div>
            </div>

            <div>
                <div class="card sticky top-24 p-5">
                    <h3 class="mb-4 font-semibold text-navy">الملخص</h3>
                    <dl class="space-y-2.5 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">المجموع</dt><dd class="num">{{ formatNumber(totals.subtotal) }}</dd></div>
                        <div v-if="totals.discount" class="flex justify-between"><dt class="text-slate-500">الخصم</dt><dd class="num text-red-600">- {{ formatNumber(totals.discount) }}</dd></div>
                        <div v-if="hasTax" class="flex justify-between"><dt class="text-slate-500">الضريبة</dt><dd class="num">{{ formatNumber(totals.tax) }}</dd></div>
                        <div class="flex justify-between border-t border-slate-100 pt-3 text-base font-bold text-navy"><dt>الإجمالي</dt><dd class="num">{{ money(totals.total, form.currency) }}</dd></div>
                    </dl>
                    <Btn class="mt-5 w-full" :loading="form.processing" @click="save"><Save class="size-4" /> حفظ المسودة</Btn>
                </div>
            </div>
        </div>

        <Modal v-model="schedOpen" title="دفعات العقود غير المفوترة">
            <p v-if="schedLoading" class="py-6 text-center text-sm text-slate-500">جارٍ التحميل…</p>
            <ul v-else-if="schedRows.length" class="divide-y divide-slate-100 text-sm">
                <li v-for="r in schedRows" :key="r.contract_schedule_id" class="flex items-center gap-3 py-2.5">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-slate-800">{{ r.name }}</p>
                        <p class="text-xs text-slate-500"><span class="num">{{ r.contract }}</span> · يستحق {{ formatDate(r.due_date) }}</p>
                    </div>
                    <span class="num whitespace-nowrap font-semibold">{{ money(r.unit_price, r.currency) }}</span>
                    <Btn size="sm" :variant="inForm(r.contract_schedule_id) ? 'ghost' : 'secondary'" :disabled="inForm(r.contract_schedule_id)" @click="addSchedule(r)">{{ inForm(r.contract_schedule_id) ? 'مضافة' : 'إضافة' }}</Btn>
                </li>
            </ul>
            <p v-else class="py-6 text-center text-sm text-slate-500">لا دفعات معلّقة غير مفوترة لعقود هذا العميل.</p>
            <template #footer><Btn variant="secondary" @click="schedOpen = false">تم</Btn></template>
        </Modal>

        <Modal v-model="timeOpen" title="الساعات المعتمدة غير المفوترة">
            <p class="mb-3 text-xs text-slate-500">الوقت القابل للفوترة في أسابيع معتمدة من جداول الساعات، مجمّعاً لكل مهمة. يُضاف كل اختيار بنداً بالساعات.</p>
            <ul v-if="timeGroups.length" class="mb-4 divide-y divide-slate-100 text-sm">
                <li v-for="(g, i) in timeGroups" :key="i" class="flex items-center gap-3 py-2">
                    <input v-model="timePick" type="checkbox" :value="i" class="rounded" />
                    <span class="min-w-0 flex-1 truncate">{{ g.title }}</span>
                    <span class="num whitespace-nowrap text-slate-600">{{ formatNumber(hours(g.minutes)) }} س</span>
                </li>
            </ul>
            <p v-else class="py-6 text-center text-sm text-slate-500">لا ساعات معتمدة غير مفوترة لهذا المشروع.</p>
            <Field v-if="timeGroups.length" :label="`سعر الساعة (${form.currency})`" id="t-rate"><input id="t-rate" v-model="timeRate" type="number" min="0" step="0.01" dir="ltr" class="field-input text-start" /></Field>
            <template #footer>
                <Btn variant="secondary" @click="timeOpen = false">إلغاء</Btn>
                <Btn :disabled="!timePick.length" @click="addTime">إضافة</Btn>
            </template>
        </Modal>
    </AppLayout>
</template>

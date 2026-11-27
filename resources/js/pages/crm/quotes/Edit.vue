<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2, ChevronUp, ChevronDown, Package, Save } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Field from '@/components/Field.vue';
import CrmTabs from '@/components/crm/CrmTabs.vue';
import { formatNumber } from '@/lib/format';
import { money } from '@/lib/crm';

const props = defineProps({
    quote: Object, form: Object, clients: Array, services: Array, categories: Array,
    units: Array, users: Array, currencies: Array,
});

const form = useForm({
    ...props.form,
    contact_id: props.form.contact_id ?? '',
    opportunity_id: props.form.opportunity_id ?? '',
    owner_id: props.form.owner_id ?? '',
    client_id: props.form.client_id ?? '',
    items: props.form.items.map((i) => ({ ...i, _key: Math.random() })),
});

const client = computed(() => props.clients.find((c) => c.id === Number(form.client_id)));
watch(() => form.client_id, (v, old) => {
    if (old === undefined || props.quote) return;
    form.contact_id = '';
    if (!client.value?.opportunities.some((o) => o.id === Number(form.opportunity_id))) form.opportunity_id = '';
    if (client.value && !form.items.length) form.currency = client.value.currency || form.currency;
});
watch(() => form.opportunity_id, (v) => {
    const o = client.value?.opportunities.find((x) => x.id === Number(v));
    if (o && !form.title) form.title = o.title;
});

const taxRate = (categoryId) => Number(props.categories.find((c) => c.id === Number(categoryId))?.tax_rate ?? 0);
const servicesByCategory = computed(() =>
    props.categories.map((c) => ({ ...c, services: props.services.filter((s) => s.service_category_id === c.id) })).filter((g) => g.services.length),
);

const picker = ref('');
function addService() {
    const s = props.services.find((x) => x.id === Number(picker.value));
    picker.value = '';
    if (!s) return;
    form.items.push({
        _key: Math.random(), service_id: s.id, service_category_id: s.service_category_id, name: s.name,
        details: s.description ?? '', quantity: 1, unit: s.unit ?? '', unit_price: Number(s.price), discount_percent: 0,
        _currency: s.currency,
    });
}
function addBlank() {
    form.items.push({ _key: Math.random(), service_id: null, service_category_id: props.categories[0]?.id ?? null, name: '', details: '', quantity: 1, unit: '', unit_price: 0, discount_percent: 0 });
}
const remove = (i) => form.items.splice(i, 1);
function move(i, d) {
    const j = i + d;
    if (j < 0 || j >= form.items.length) return;
    const [x] = form.items.splice(i, 1);
    form.items.splice(j, 0, x);
}

// Same rounding as App\Models\QuoteItem.
const r2 = (v) => Math.round((Number(v) + Number.EPSILON) * 100) / 100;
const line = (it) => {
    const gross = r2(Number(it.quantity || 0) * Number(it.unit_price || 0));
    const discount = r2(gross * Number(it.discount_percent || 0) / 100);
    const net = r2(gross - discount);
    return { gross, discount, net, tax: r2(net * taxRate(it.service_category_id) / 100) };
};
const totals = computed(() => {
    const t = { subtotal: 0, discount: 0, net: 0, tax: 0 };
    form.items.forEach((it) => { const l = line(it); t.subtotal += l.gross; t.discount += l.discount; t.net += l.net; t.tax += l.tax; });
    Object.keys(t).forEach((k) => (t[k] = r2(t[k])));
    return { ...t, total: r2(t.net + t.tax) };
});
const hasTax = computed(() => form.items.some((it) => taxRate(it.service_category_id) > 0));
const itemError = (i, f) => form.errors[`items.${i}.${f}`];

function save() {
    form.transform((d) => ({
        ...d,
        contact_id: d.contact_id || null,
        opportunity_id: d.opportunity_id || null,
        owner_id: d.owner_id || null,
        valid_until: d.valid_until || null,
        items: d.items.map(({ _key, _currency, ...it }) => ({ ...it, service_category_id: it.service_category_id || null })),
    }));
    props.quote ? form.put(`/crm/quotes/${props.quote.id}`) : form.post('/crm/quotes');
}
const heading = computed(() => (props.quote ? 'تعديل مسودة العرض' : 'عرض سعر جديد'));
</script>

<template>
    <AppLayout :title="heading">
        <PageHeader :title="heading" subtitle="المسودة بلا رقم. يُمنح الرقم ويُثبَّت سعر الصرف والضريبة عند الإصدار.">
            <template #actions>
                <Btn variant="secondary" :href="quote ? `/crm/quotes/${quote.id}` : '/crm/quotes'">إلغاء</Btn>
                <Btn :loading="form.processing" @click="save"><Save class="size-4" /> حفظ المسودة</Btn>
            </template>
        </PageHeader>
        <CrmTabs />

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <!-- Header details -->
                <div class="card grid gap-4 p-5 sm:grid-cols-2">
                    <Field label="العميل" id="q-client" :error="form.errors.client_id" required>
                        <select id="q-client" v-model="form.client_id" class="field-input" :disabled="!!quote">
                            <option value="">اختر العميل…</option>
                            <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </Field>
                    <Field label="الفرصة" id="q-opp" :error="form.errors.opportunity_id" hint="عند الإصدار تنتقل الفرصة إلى «العرض مُرسَل»، وعند القبول إلى «مكسوبة».">
                        <select id="q-opp" v-model="form.opportunity_id" class="field-input" :disabled="!client">
                            <option value="">بلا فرصة</option>
                            <option v-for="o in client?.opportunities ?? []" :key="o.id" :value="o.id">{{ o.title }}</option>
                        </select>
                    </Field>
                    <Field label="عنوان العرض" id="q-title" :error="form.errors.title" required class="sm:col-span-2">
                        <input id="q-title" v-model="form.title" class="field-input" placeholder="مثال: إدارة منصات التواصل — 3 أشهر" />
                    </Field>
                    <Field label="جهة الاتصال" id="q-contact" :error="form.errors.contact_id">
                        <select id="q-contact" v-model="form.contact_id" class="field-input" :disabled="!client">
                            <option value="">—</option>
                            <option v-for="c in client?.contacts ?? []" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </Field>
                    <div class="grid grid-cols-2 gap-4">
                        <Field label="العملة" id="q-cur" :error="form.errors.currency" required>
                            <select id="q-cur" v-model="form.currency" class="field-input"><option v-for="c in currencies" :key="c">{{ c }}</option></select>
                        </Field>
                        <Field label="صالح حتى" id="q-valid" :error="form.errors.valid_until">
                            <input id="q-valid" v-model="form.valid_until" type="date" dir="ltr" class="field-input" />
                        </Field>
                    </div>
                    <Field label="المسؤول" id="q-owner" :error="form.errors.owner_id">
                        <select id="q-owner" v-model="form.owner_id" class="field-input"><option value="">أنا</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select>
                    </Field>
                </div>

                <!-- Lines -->
                <div class="card overflow-hidden">
                    <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 p-4">
                        <h3 class="me-auto font-semibold text-navy">بنود العرض</h3>
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
                        اختر خدمة من الكتالوج أو أضف بنداً حراً.
                    </div>

                    <div v-for="(it, i) in form.items" :key="it._key" class="border-b border-slate-100 p-4 last:border-b-0">
                        <div class="flex gap-3">
                            <div class="flex flex-col items-center gap-1 pt-1 text-slate-400">
                                <span class="num text-xs font-semibold">{{ i + 1 }}</span>
                                <button type="button" class="rounded p-0.5 hover:bg-slate-100 hover:text-navy disabled:opacity-30" :disabled="i === 0" @click="move(i, -1)"><ChevronUp class="size-4" /></button>
                                <button type="button" class="rounded p-0.5 hover:bg-slate-100 hover:text-navy disabled:opacity-30" :disabled="i === form.items.length - 1" @click="move(i, 1)"><ChevronDown class="size-4" /></button>
                            </div>
                            <div class="min-w-0 flex-1 space-y-3">
                                <div class="grid gap-3 sm:grid-cols-3">
                                    <Field :error="itemError(i, 'name')" class="sm:col-span-2">
                                        <input v-model="it.name" class="field-input font-medium" placeholder="اسم البند" />
                                    </Field>
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
                                    <div class="col-span-2 sm:col-span-1">
                                        <p class="field-label">المجموع</p>
                                        <p class="num py-2.5 font-semibold text-navy">{{ formatNumber(line(it).net) }}</p>
                                    </div>
                                </div>
                                <p v-if="it._currency && it._currency !== form.currency" class="text-xs text-amber-700">سعر الكتالوج بعملة {{ it._currency }}؛ عدّل السعر ليناسب عملة العرض ({{ form.currency }}).</p>
                            </div>
                            <button type="button" class="self-start rounded-lg p-2 text-slate-400 hover:bg-red-50 hover:text-red-600" title="حذف البند" @click="remove(i)"><Trash2 class="size-4" /></button>
                        </div>
                    </div>
                </div>

                <div class="card space-y-4 p-5">
                    <Field label="مقدمة العرض" id="q-intro" :error="form.errors.intro" hint="تظهر تحت عنوان العرض في ملف PDF.">
                        <textarea id="q-intro" v-model="form.intro" rows="3" class="field-input" />
                    </Field>
                    <Field label="الشروط والأحكام" id="q-terms" :error="form.errors.terms">
                        <textarea id="q-terms" v-model="form.terms" rows="4" class="field-input" />
                    </Field>
                    <Field label="ملاحظات للعميل" id="q-notes" :error="form.errors.notes">
                        <textarea id="q-notes" v-model="form.notes" rows="2" class="field-input" />
                    </Field>
                </div>
            </div>

            <!-- Totals -->
            <div>
                <div class="card sticky top-24 p-5">
                    <h3 class="mb-4 font-semibold text-navy">الملخص</h3>
                    <dl class="space-y-2.5 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">المجموع</dt><dd class="num">{{ formatNumber(totals.subtotal) }}</dd></div>
                        <div v-if="totals.discount" class="flex justify-between"><dt class="text-slate-500">الخصم</dt><dd class="num text-red-600">- {{ formatNumber(totals.discount) }}</dd></div>
                        <div v-if="hasTax" class="flex justify-between"><dt class="text-slate-500">الضريبة</dt><dd class="num">{{ formatNumber(totals.tax) }}</dd></div>
                        <div class="flex justify-between border-t border-slate-100 pt-3 text-base font-bold text-navy"><dt>الإجمالي</dt><dd class="num">{{ money(totals.total, form.currency) }}</dd></div>
                    </dl>
                    <p class="mt-4 text-xs text-slate-500"><span class="num">{{ form.items.length }}</span> بند. الضريبة حسب نسبة تصنيف كل بند، وتُثبَّت عند الإصدار.</p>
                    <Btn class="mt-5 w-full" :loading="form.processing" @click="save"><Save class="size-4" /> حفظ المسودة</Btn>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

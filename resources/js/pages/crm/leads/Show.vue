<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Pencil, Archive, ArrowLeftRight, Merge, Mail, Phone, Building2, UserRound, ArrowRight } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import Toggle from '@/components/Toggle.vue';
import CrmTabs from '@/components/crm/CrmTabs.vue';
import LeadFormModal from '@/components/crm/LeadFormModal.vue';
import ActivityFeed from '@/components/crm/ActivityFeed.vue';
import DuplicateList from '@/components/crm/DuplicateList.vue';
import { useAuth } from '@/lib/auth';
import { formatDate } from '@/lib/format';
import { ratingTone, money } from '@/lib/crm';

const props = defineProps({
    lead: Object, activities: Array, duplicates: Array, clients: Array,
    sources: Object, leadStages: Object, ratings: Object, oppStages: Array, lostReasons: Object,
    activityTypes: Object, clientTypes: Object, users: Array, currencies: Array,
});
const { can } = useAuth();
const options = computed(() => props);
const converted = computed(() => props.lead.stage === 'converted');
const editable = computed(() => props.lead.can_edit && !converted.value);

const editing = ref(false);
const archive = () => confirm('أرشفة هذا السجل؟') && router.delete(`/crm/leads/${props.lead.id}`);
const merge = (d) => confirm(`دمج «${d.name}» في هذا السجل؟ تُنقل أنشطته وتُكمل الحقول الفارغة هنا، ثم يُؤرشف.`)
    && router.post(`/crm/leads/${props.lead.id}/merge`, { duplicate_id: d.id }, { preserveScroll: true });

// Conversion wizard
const converting = ref(false);
const step = ref(1);
const conv = useForm({
    mode: 'new',
    client_id: '',
    client: { name: props.lead.company_name || props.lead.name, type: props.lead.company_name ? 'company' : 'individual' },
    contact: { name: props.lead.name, job_title: props.lead.job_title || '' },
    create_opportunity: true,
    opportunity: { title: props.lead.interest || '', amount: props.lead.expected_value || '', currency: props.lead.currency || 'USD', stage: 'proposal', expected_close_date: '' },
});
const existingClient = computed(() => props.clients.find((c) => c.id === Number(conv.client_id)));
const openConvert = () => { step.value = 1; converting.value = true; };
function submitConvert() {
    conv.transform((d) => ({
        client_id: d.mode === 'existing' ? d.client_id : null,
        client: d.mode === 'new' ? d.client : {},
        contact: d.contact,
        create_opportunity: d.create_opportunity,
        opportunity: d.create_opportunity ? d.opportunity : null,
    })).post(`/crm/leads/${props.lead.id}/convert`, { onSuccess: () => (converting.value = false) });
}
const openStages = computed(() => props.oppStages.filter((s) => !['won', 'lost'].includes(s.key)));
</script>

<template>
    <AppLayout :title="lead.company_name || lead.name">
        <PageHeader :title="lead.company_name || lead.name" :subtitle="lead.company_name ? lead.name + (lead.job_title ? ' · ' + lead.job_title : '') : lead.job_title">
            <template #actions>
                <Btn v-if="editable && can('crm.convert')" @click="openConvert"><ArrowLeftRight class="size-4" /> تحويل إلى عميل</Btn>
                <Btn v-if="editable" variant="secondary" @click="editing = true"><Pencil class="size-4" /> تعديل</Btn>
                <Btn v-if="can('crm.delete') && !converted" variant="ghost" @click="archive"><Archive class="size-4" /></Btn>
            </template>
        </PageHeader>
        <CrmTabs />

        <div v-if="converted" class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">
            تم تحويله إلى عميل في {{ formatDate(lead.converted_at) }}.
            <Link v-if="lead.client" :href="`/crm/clients/${lead.client.id}`" class="font-semibold underline">فتح ملف {{ lead.client.name }}</Link>
        </div>
        <DuplicateList v-if="editable" class="mb-4" :items="duplicates">
            <template #action="{ item }">
                <button v-if="item.type === 'lead' && can('crm.delete')" class="ms-auto flex items-center gap-1 rounded-lg bg-white px-2 py-1 text-xs font-semibold text-amber-900 hover:bg-amber-100" @click="merge(item)"><Merge class="size-3.5" /> دمج هنا</button>
            </template>
        </DuplicateList>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6">
                <div class="card p-5">
                    <div class="mb-4 flex flex-wrap gap-2">
                        <Badge tone="navy">{{ converted ? 'تم التحويل' : leadStages[lead.stage] }}</Badge>
                        <Badge v-if="lead.rating" :tone="ratingTone[lead.rating]">{{ ratings[lead.rating] }}</Badge>
                        <Badge v-if="lead.source">{{ sources[lead.source] }}</Badge>
                    </div>
                    <dl class="space-y-3 text-sm">
                        <div v-if="lead.phone" class="flex items-center gap-2"><Phone class="size-4 text-slate-400" /><a :href="`tel:${lead.phone}`" dir="ltr" class="text-slate-700 hover:text-brand">{{ lead.phone }}</a></div>
                        <div v-if="lead.email" class="flex items-center gap-2"><Mail class="size-4 text-slate-400" /><a :href="`mailto:${lead.email}`" dir="ltr" class="text-slate-700 hover:text-brand">{{ lead.email }}</a></div>
                        <div v-if="lead.company_name" class="flex items-center gap-2"><Building2 class="size-4 text-slate-400" />{{ lead.company_name }}</div>
                        <div class="flex items-center gap-2"><UserRound class="size-4 text-slate-400" />المسؤول: {{ lead.owner?.name ?? '—' }}</div>
                    </dl>
                    <div class="mt-5 grid grid-cols-2 gap-3 border-t border-slate-100 pt-4 text-sm">
                        <div><p class="text-xs text-slate-500">القيمة المتوقعة</p><p class="num font-semibold text-navy">{{ money(lead.expected_value, lead.currency) }}</p></div>
                        <div><p class="text-xs text-slate-500">أُضيف</p><p>{{ formatDate(lead.created_at) }}</p></div>
                    </div>
                    <div v-if="lead.interest" class="mt-4 border-t border-slate-100 pt-4 text-sm"><p class="text-xs text-slate-500">يهتم بـ</p><p>{{ lead.interest }}</p></div>
                    <div v-if="lead.notes" class="mt-4 border-t border-slate-100 pt-4 text-sm"><p class="text-xs text-slate-500">ملاحظات</p><p class="whitespace-pre-line text-slate-700">{{ lead.notes }}</p></div>
                </div>
            </div>
            <div class="lg:col-span-2">
                <ActivityFeed :activities="activities" subject-type="lead" :subject-id="lead.id" :options="options" :readonly="converted" />
            </div>
        </div>

        <LeadFormModal v-model="editing" :lead="lead" :options="options" />

        <Modal v-model="converting" title="تحويل إلى عميل" wide>
            <ol class="mb-5 flex gap-2 text-xs">
                <li v-for="(s, i) in ['العميل', 'جهة الاتصال', 'الفرصة']" :key="i" class="flex-1 rounded-full py-1.5 text-center"
                    :class="step === i + 1 ? 'bg-navy text-white' : step > i + 1 ? 'bg-navy-50 text-navy' : 'bg-slate-100 text-slate-500'">{{ i + 1 }}. {{ s }}</li>
            </ol>

            <div v-if="step === 1" class="space-y-4">
                <div class="flex gap-2">
                    <button class="flex-1 rounded-xl border p-3 text-sm" :class="conv.mode === 'new' ? 'border-navy bg-navy-50 text-navy font-semibold' : 'border-slate-200'" @click="conv.mode = 'new'">عميل جديد</button>
                    <button class="flex-1 rounded-xl border p-3 text-sm" :class="conv.mode === 'existing' ? 'border-navy bg-navy-50 text-navy font-semibold' : 'border-slate-200'" @click="conv.mode = 'existing'">إضافته لعميل موجود</button>
                </div>
                <template v-if="conv.mode === 'new'">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <Field label="اسم العميل" id="c-n" :error="conv.errors['client.name']"><input id="c-n" v-model="conv.client.name" class="field-input" /></Field>
                        <Field label="النوع" id="c-t"><select id="c-t" v-model="conv.client.type" class="field-input"><option v-for="(l, k) in clientTypes" :key="k" :value="k">{{ l }}</option></select></Field>
                    </div>
                    <p class="text-xs text-slate-500">الهاتف والبريد والمصدر والمسؤول تُنقل من السجل تلقائياً.</p>
                </template>
                <Field v-else label="العميل" id="c-e" :error="conv.errors.client_id">
                    <select id="c-e" v-model="conv.client_id" class="field-input"><option value="">اختر…</option><option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                </Field>
            </div>

            <div v-if="step === 2" class="grid gap-4 sm:grid-cols-2">
                <Field label="اسم جهة الاتصال" id="ct-n"><input id="ct-n" v-model="conv.contact.name" class="field-input" /></Field>
                <Field label="المسمى الوظيفي" id="ct-j"><input id="ct-j" v-model="conv.contact.job_title" class="field-input" /></Field>
            </div>

            <div v-if="step === 3" class="space-y-4">
                <Toggle v-model="conv.create_opportunity" label="إنشاء فرصة بيع الآن" />
                <div v-if="conv.create_opportunity" class="grid gap-4 sm:grid-cols-2">
                    <Field label="عنوان الفرصة" id="o-t" :error="conv.errors['opportunity.title']" required class="sm:col-span-2"><input id="o-t" v-model="conv.opportunity.title" class="field-input" /></Field>
                    <Field label="القيمة" id="o-a" :error="conv.errors['opportunity.amount']">
                        <div class="flex gap-2">
                            <input id="o-a" v-model="conv.opportunity.amount" type="number" min="0" step="0.01" dir="ltr" class="field-input text-start" />
                            <select v-model="conv.opportunity.currency" class="field-input w-24"><option v-for="c in currencies" :key="c">{{ c }}</option></select>
                        </div>
                    </Field>
                    <Field label="المرحلة" id="o-s"><select id="o-s" v-model="conv.opportunity.stage" class="field-input"><option v-for="s in openStages" :key="s.key" :value="s.key">{{ s.label }} ({{ s.probability }}%)</option></select></Field>
                    <Field label="تاريخ الإغلاق المتوقع" id="o-d"><input id="o-d" v-model="conv.opportunity.expected_close_date" type="date" dir="ltr" class="field-input" /></Field>
                </div>
                <p class="rounded-xl bg-slate-50 p-3 text-sm text-slate-600">
                    سيُنشأ: <strong>{{ conv.mode === 'existing' ? (existingClient?.name ?? '—') : conv.client.name }}</strong>
                    ← جهة اتصال <strong>{{ conv.contact.name }}</strong>
                    <template v-if="conv.create_opportunity"> ← فرصة <strong>{{ conv.opportunity.title || '—' }}</strong></template>.
                    الأنشطة السابقة تنتقل إلى ملف العميل.
                </p>
                <p v-if="conv.errors.lead" class="field-error">{{ conv.errors.lead }}</p>
            </div>

            <template #footer>
                <Btn v-if="step > 1" variant="secondary" @click="step--"><ArrowRight class="size-4" /> السابق</Btn>
                <Btn v-if="step < 3" :disabled="step === 1 && conv.mode === 'existing' && !conv.client_id" @click="step++">التالي</Btn>
                <Btn v-else :loading="conv.processing" @click="submitConvert">تحويل</Btn>
            </template>
        </Modal>
    </AppLayout>
</template>

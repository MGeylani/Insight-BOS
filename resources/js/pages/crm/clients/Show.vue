<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Pencil, Archive, Plus, Phone, Mail, Globe, MapPin, Star, Trash2, MessageCircle } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import Toggle from '@/components/Toggle.vue';
import CrmTabs from '@/components/crm/CrmTabs.vue';
import ClientFormModal from '@/components/crm/ClientFormModal.vue';
import OpportunityFormModal from '@/components/crm/OpportunityFormModal.vue';
import ActivityFeed from '@/components/crm/ActivityFeed.vue';
import DuplicateList from '@/components/crm/DuplicateList.vue';
import QuoteList from '@/components/crm/QuoteList.vue';
import { useAuth } from '@/lib/auth';
import { formatDate } from '@/lib/format';
import { oppStageTone, money, usd } from '@/lib/crm';

const props = defineProps({
    client: Object, contacts: Array, opportunities: Array, leads: Array, activities: Array, stats: Object, duplicates: Array, quotes: Array, quoteStatuses: Object,
    sources: Object, leadStages: Object, ratings: Object, oppStages: Array, lostReasons: Object,
    activityTypes: Object, clientTypes: Object, users: Array, currencies: Array,
});
const { can } = useAuth();
const options = computed(() => props);
const stageLabel = (k) => props.oppStages.find((s) => s.key === k)?.label;
const tab = ref('overview');

const editing = ref(false);
const addingOpp = ref(false);
const archive = () => confirm(`أرشفة العميل ${props.client.name}؟`) && router.delete(`/crm/clients/${props.client.id}`);

// Contacts
const contactOpen = ref(false);
const contactId = ref(null);
const cf = useForm({ name: '', job_title: '', email: '', phone: '', whatsapp: '', is_primary: false, notes: '' });
function editContact(c) {
    contactId.value = c?.id ?? null;
    cf.clearErrors();
    Object.assign(cf, { name: c?.name ?? '', job_title: c?.job_title ?? '', email: c?.email ?? '', phone: c?.phone ?? '', whatsapp: c?.whatsapp ?? '', is_primary: c?.is_primary ?? false, notes: c?.notes ?? '' });
    contactOpen.value = true;
}
function saveContact() {
    const opts = { preserveScroll: true, onSuccess: () => (contactOpen.value = false) };
    contactId.value ? cf.put(`/crm/clients/${props.client.id}/contacts/${contactId.value}`, opts) : cf.post(`/crm/clients/${props.client.id}/contacts`, opts);
}
const removeContact = (c) => confirm(`حذف ${c.name}؟`) && router.delete(`/crm/clients/${props.client.id}/contacts/${c.id}`, { preserveScroll: true });
const wa = (n) => `https://wa.me/${String(n).replace(/\D/g, '')}`;

const tabs = computed(() => [
    { key: 'overview', label: 'نظرة عامة' },
    { key: 'contacts', label: `جهات الاتصال (${props.contacts.length})` },
    { key: 'opportunities', label: `الفرص (${props.opportunities.length})` },
    { key: 'activities', label: `الأنشطة (${props.activities.length})` },
]);
</script>

<template>
    <AppLayout :title="client.name">
        <PageHeader :title="client.name" :subtitle="[clientTypes[client.type], client.industry, client.legal_name].filter(Boolean).join(' · ')">
            <template #actions>
                <Btn v-if="client.can_edit && can('crm.create')" @click="addingOpp = true"><Plus class="size-4" /> فرصة</Btn>
                <Btn v-if="client.can_edit" variant="secondary" @click="editing = true"><Pencil class="size-4" /> تعديل</Btn>
                <Btn v-if="can('crm.delete')" variant="ghost" @click="archive"><Archive class="size-4" /></Btn>
            </template>
        </PageHeader>
        <CrmTabs />
        <DuplicateList class="mb-4" :items="duplicates" title="سجلات أخرى تبدو للعميل نفسه" />

        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-4"><p class="text-xs text-slate-500">إجمالي المكسوب</p><p class="num mt-1 text-xl font-bold text-navy">{{ usd(stats.won_total) }}</p><p class="text-xs text-slate-400"><span class="num">{{ stats.won_count }}</span> فرصة</p></div>
            <div class="card p-4"><p class="text-xs text-slate-500">فرص مفتوحة</p><p class="num mt-1 text-xl font-bold text-brand">{{ usd(stats.open_total) }}</p><p class="text-xs text-slate-400"><span class="num">{{ stats.open_count }}</span> فرصة</p></div>
            <div class="card p-4"><p class="text-xs text-slate-500">متابعات مفتوحة</p><p class="num mt-1 text-xl font-bold text-plum">{{ stats.pending_activities }}</p></div>
            <div class="card p-4"><p class="text-xs text-slate-500">مسؤول الحساب</p><p class="mt-1 font-semibold text-slate-800">{{ client.owner?.name ?? '—' }}</p><p class="text-xs text-slate-400">عميل منذ {{ formatDate(client.created_at) }}</p></div>
        </div>

        <nav class="mb-4 flex gap-1 border-b border-slate-200">
            <button v-for="t in tabs" :key="t.key" class="-mb-px border-b-2 px-4 py-2.5 text-sm transition"
                :class="tab === t.key ? 'border-navy font-semibold text-navy' : 'border-transparent text-slate-500 hover:text-slate-700'" @click="tab = t.key">{{ t.label }}</button>
        </nav>

        <div v-if="tab === 'overview'" class="grid gap-6 lg:grid-cols-3">
            <div class="card space-y-3 p-5 text-sm">
                <div v-if="client.phone" class="flex items-center gap-2"><Phone class="size-4 text-slate-400" /><a :href="`tel:${client.phone}`" dir="ltr" class="hover:text-brand">{{ client.phone }}</a></div>
                <div v-if="client.email" class="flex items-center gap-2"><Mail class="size-4 text-slate-400" /><a :href="`mailto:${client.email}`" dir="ltr" class="hover:text-brand">{{ client.email }}</a></div>
                <div v-if="client.website" class="flex items-center gap-2"><Globe class="size-4 text-slate-400" /><a :href="client.website.startsWith('http') ? client.website : 'https://' + client.website" target="_blank" rel="noopener" dir="ltr" class="hover:text-brand">{{ client.website }}</a></div>
                <div v-if="client.city || client.country || client.address" class="flex items-start gap-2"><MapPin class="mt-0.5 size-4 text-slate-400" />{{ [client.address, client.city, client.country].filter(Boolean).join('، ') }}</div>
                <dl class="grid grid-cols-2 gap-3 border-t border-slate-100 pt-3">
                    <div><dt class="text-xs text-slate-500">عملة التعامل</dt><dd>{{ client.currency }}</dd></div>
                    <div><dt class="text-xs text-slate-500">المصدر</dt><dd>{{ sources[client.source] ?? '—' }}</dd></div>
                    <div v-if="client.tax_number" class="col-span-2"><dt class="text-xs text-slate-500">الرقم الضريبي</dt><dd dir="ltr" class="text-start">{{ client.tax_number }}</dd></div>
                </dl>
                <div v-if="client.notes" class="border-t border-slate-100 pt-3"><p class="text-xs text-slate-500">ملاحظات</p><p class="whitespace-pre-line">{{ client.notes }}</p></div>
                <div v-if="leads.length" class="border-t border-slate-100 pt-3"><p class="text-xs text-slate-500">جاء من</p>
                    <p v-for="l in leads" :key="l.id"><Link :href="`/crm/leads/${l.id}`" class="text-brand hover:underline">{{ l.name }}</Link> <span class="text-xs text-slate-400">· {{ sources[l.source] ?? '' }} · {{ formatDate(l.converted_at) }}</span></p>
                </div>
            </div>
            <div class="space-y-6 lg:col-span-2">
                <div class="card">
                    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3.5"><h3 class="font-semibold text-navy">الفرص الأخيرة</h3><button class="text-sm text-brand" @click="tab = 'opportunities'">الكل</button></div>
                    <ul v-if="opportunities.length" class="divide-y divide-slate-100">
                        <li v-for="o in opportunities.slice(0, 4)" :key="o.id"><Link :href="`/crm/opportunities/${o.id}`" class="flex items-center justify-between gap-3 px-5 py-3 hover:bg-navy-50/40">
                            <span class="font-medium">{{ o.title }}</span><span class="flex items-center gap-3"><span class="num text-sm">{{ money(o.amount, o.currency) }}</span><Badge :tone="oppStageTone[o.stage]">{{ stageLabel(o.stage) }}</Badge></span>
                        </Link></li>
                    </ul>
                    <p v-else class="px-5 py-6 text-center text-sm text-slate-400">لا توجد فرص.</p>
                </div>
                <QuoteList :quotes="quotes" :statuses="quoteStatuses" :create-href="`/crm/quotes/create?client_id=${client.id}`" />
                <ActivityFeed :activities="activities.slice(0, 5)" subject-type="client" :subject-id="client.id" :options="options" />
            </div>
        </div>

        <div v-else-if="tab === 'contacts'" class="card overflow-hidden">
            <div class="flex justify-end border-b border-slate-100 p-3"><Btn v-if="client.can_edit" size="sm" variant="secondary" @click="editContact(null)"><Plus class="size-3.5" /> جهة اتصال</Btn></div>
            <table v-if="contacts.length" class="tbl">
                <thead><tr><th>الاسم</th><th>المسمى</th><th>الهاتف</th><th>البريد</th><th class="w-px"></th></tr></thead>
                <tbody>
                    <tr v-for="c in contacts" :key="c.id">
                        <td class="font-medium"><Star v-if="c.is_primary" class="me-1 inline size-3.5 fill-sand text-amber-500" title="رئيسية" />{{ c.name }}</td>
                        <td class="text-slate-600">{{ c.job_title ?? '—' }}</td>
                        <td dir="ltr" class="text-start"><a v-if="c.phone" :href="`tel:${c.phone}`" class="hover:text-brand">{{ c.phone }}</a><a v-if="c.whatsapp" :href="wa(c.whatsapp)" target="_blank" rel="noopener" class="ms-2 inline-flex text-emerald-600" title="واتساب"><MessageCircle class="size-4" /></a></td>
                        <td dir="ltr" class="text-start"><a v-if="c.email" :href="`mailto:${c.email}`" class="hover:text-brand">{{ c.email }}</a></td>
                        <td><div v-if="client.can_edit" class="flex gap-1">
                            <button class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-navy" @click="editContact(c)"><Pencil class="size-4" /></button>
                            <button class="rounded-lg p-2 text-slate-400 hover:bg-red-50 hover:text-red-600" @click="removeContact(c)"><Trash2 class="size-4" /></button>
                        </div></td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="px-5 py-8 text-center text-sm text-slate-400">لا توجد جهات اتصال.</p>
        </div>

        <div v-else-if="tab === 'opportunities'" class="card overflow-hidden">
            <table v-if="opportunities.length" class="tbl">
                <thead><tr><th>الفرصة</th><th>المرحلة</th><th>القيمة</th><th>الإغلاق المتوقع</th><th>المسؤول</th></tr></thead>
                <tbody>
                    <tr v-for="o in opportunities" :key="o.id" class="cursor-pointer" @click="router.visit(`/crm/opportunities/${o.id}`)">
                        <td class="font-medium">{{ o.title }}</td>
                        <td><Badge :tone="oppStageTone[o.stage]">{{ stageLabel(o.stage) }} · <span class="num">{{ o.probability }}%</span></Badge></td>
                        <td class="num">{{ money(o.amount, o.currency) }}</td>
                        <td>{{ formatDate(o.expected_close_date) }}</td>
                        <td class="text-slate-600">{{ o.owner?.name ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="px-5 py-8 text-center text-sm text-slate-400">لا توجد فرص.</p>
        </div>

        <ActivityFeed v-else :activities="activities" subject-type="client" :subject-id="client.id" :options="options" />

        <ClientFormModal v-model="editing" :client="client" :options="options" />
        <OpportunityFormModal v-model="addingOpp" :options="options" :client-id="client.id" :contacts="contacts" />
        <Modal v-model="contactOpen" :title="contactId ? 'تعديل جهة الاتصال' : 'جهة اتصال جديدة'">
            <div class="grid gap-4 sm:grid-cols-2">
                <Field label="الاسم" id="ct-n" :error="cf.errors.name" required><input id="ct-n" v-model="cf.name" class="field-input" /></Field>
                <Field label="المسمى الوظيفي" id="ct-j"><input id="ct-j" v-model="cf.job_title" class="field-input" /></Field>
                <Field label="الهاتف" id="ct-p" :error="cf.errors.phone"><input id="ct-p" v-model="cf.phone" dir="ltr" class="field-input text-start" /></Field>
                <Field label="واتساب" id="ct-w"><input id="ct-w" v-model="cf.whatsapp" dir="ltr" class="field-input text-start" /></Field>
                <Field label="البريد" id="ct-e" :error="cf.errors.email" class="sm:col-span-2"><input id="ct-e" v-model="cf.email" type="email" dir="ltr" class="field-input text-start" /></Field>
                <Field label="ملاحظات" id="ct-no" class="sm:col-span-2"><textarea id="ct-no" v-model="cf.notes" rows="2" class="field-input" /></Field>
                <Toggle v-model="cf.is_primary" label="جهة الاتصال الرئيسية" />
            </div>
            <template #footer><Btn variant="secondary" @click="contactOpen = false">إلغاء</Btn><Btn :loading="cf.processing" @click="saveContact">حفظ</Btn></template>
        </Modal>
    </AppLayout>
</template>

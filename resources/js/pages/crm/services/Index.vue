<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Plus, Pencil, Archive, Search } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Field from '@/components/Field.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import Toggle from '@/components/Toggle.vue';
import EmptyState from '@/components/EmptyState.vue';
import CrmTabs from '@/components/crm/CrmTabs.vue';
import { formatNumber } from '@/lib/format';
import { money } from '@/lib/crm';

const props = defineProps({ services: Array, categories: Array, currencies: Array, units: Array, canManage: Boolean });

const search = ref('');
const groups = computed(() => {
    const s = search.value.trim();
    const list = s ? props.services.filter((x) => (x.name + ' ' + (x.description ?? '')).includes(s)) : props.services;
    return props.categories
        .map((c) => ({ ...c, services: list.filter((x) => x.service_category_id === c.id) }))
        .filter((g) => g.services.length);
});

const open = ref(false);
const editingId = ref(null);
const blank = () => ({ service_category_id: props.categories[0]?.id ?? '', name: '', description: '', unit: '', price: 0, currency: 'USD', is_active: true, sort: 0 });
const form = useForm(blank());

function edit(s) {
    editingId.value = s?.id ?? null;
    form.clearErrors();
    const src = s ?? blank();
    Object.keys(blank()).forEach((k) => (form[k] = src[k] ?? blank()[k]));
    if (s) form.price = Number(s.price);
    open.value = true;
}
function save() {
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    editingId.value ? form.put(`/crm/services/${editingId.value}`, opts) : form.post('/crm/services', opts);
}
const archive = (s) => confirm(`أرشفة الخدمة «${s.name}»؟ لن تظهر في العروض الجديدة، وتبقى في العروض السابقة.`) && router.delete(`/crm/services/${s.id}`, { preserveScroll: true });
</script>

<template>
    <AppLayout title="كتالوج الخدمات">
        <PageHeader title="العملاء والمبيعات" subtitle="كتالوج الخدمات: أسعار ووحدات افتراضية تُنسخ إلى بنود عروض الأسعار ويمكن تعديلها في كل عرض.">
            <template #actions><Btn v-if="canManage" @click="edit(null)"><Plus class="size-4" /> خدمة</Btn></template>
        </PageHeader>
        <CrmTabs />

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <div class="relative min-w-56 flex-1 sm:max-w-sm">
                <Search class="absolute inset-y-0 start-3 my-auto size-4 text-slate-400" />
                <input v-model="search" type="search" placeholder="ابحث في الخدمات" class="field-input ps-9" />
            </div>
            <p v-if="canManage" class="text-xs text-slate-500">نسبة الضريبة تأتي من <a href="/settings/service-categories" class="text-brand hover:underline">تصنيف الخدمة</a>.</p>
        </div>

        <div v-if="!groups.length" class="card">
            <EmptyState title="لا توجد خدمات بعد" :text="canManage ? 'أضف خدماتك المعتادة بأسعارها لتختارها بسرعة عند إعداد عرض السعر.' : 'لم يُضف أي خدمة إلى الكتالوج بعد.'">
                <Btn v-if="canManage" @click="edit(null)"><Plus class="size-4" /> أول خدمة</Btn>
            </EmptyState>
        </div>

        <div v-for="g in groups" :key="g.id" class="card mb-5 overflow-hidden">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                <h3 class="font-semibold text-navy">{{ g.name }}</h3>
                <span class="text-xs text-slate-500">الضريبة <span class="num">{{ formatNumber(g.tax_rate) }}%</span></span>
            </div>
            <table class="tbl">
                <thead><tr><th>الخدمة</th><th>الوحدة</th><th>السعر</th><th>الحالة</th><th v-if="canManage" class="w-px"></th></tr></thead>
                <tbody>
                    <tr v-for="s in g.services" :key="s.id" :class="{ 'opacity-50': !s.is_active }">
                        <td><p class="font-medium text-slate-800">{{ s.name }}</p><p v-if="s.description" class="line-clamp-2 max-w-xl text-xs text-slate-500">{{ s.description }}</p></td>
                        <td class="text-slate-600">{{ s.unit || '—' }}</td>
                        <td class="num font-semibold text-navy">{{ money(s.price, s.currency) }}</td>
                        <td><Badge :tone="s.is_active ? 'green' : 'slate'">{{ s.is_active ? 'متاحة' : 'متوقفة' }}</Badge></td>
                        <td v-if="canManage" class="whitespace-nowrap">
                            <button class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-navy" title="تعديل" @click="edit(s)"><Pencil class="size-4" /></button>
                            <button class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-red-600" title="أرشفة" @click="archive(s)"><Archive class="size-4" /></button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal v-model="open" :title="editingId ? 'تعديل الخدمة' : 'خدمة جديدة'">
            <div class="space-y-4">
                <Field label="التصنيف" id="sv-c" :error="form.errors.service_category_id" required>
                    <select id="sv-c" v-model="form.service_category_id" class="field-input"><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                </Field>
                <Field label="اسم الخدمة" id="sv-n" :error="form.errors.name" required><input id="sv-n" v-model="form.name" class="field-input" placeholder="مثال: إدارة حساب إنستغرام" /></Field>
                <Field label="الوصف" id="sv-d" :error="form.errors.description" hint="يُنسخ إلى تفاصيل البند في العرض."><textarea id="sv-d" v-model="form.description" rows="3" class="field-input" /></Field>
                <div class="grid gap-4 sm:grid-cols-2">
                    <Field label="السعر الافتراضي" id="sv-p" :error="form.errors.price" required>
                        <div class="flex gap-2">
                            <input id="sv-p" v-model="form.price" type="number" min="0" step="0.01" dir="ltr" class="field-input text-start" />
                            <select v-model="form.currency" class="field-input w-24"><option v-for="c in currencies" :key="c">{{ c }}</option></select>
                        </div>
                    </Field>
                    <Field label="الوحدة" id="sv-u" :error="form.errors.unit">
                        <input id="sv-u" v-model="form.unit" list="sv-units" class="field-input" placeholder="شهر، فيديو، مشروع…" />
                        <datalist id="sv-units"><option v-for="u in units" :key="u" :value="u" /></datalist>
                    </Field>
                    <Field label="الترتيب" id="sv-s" :error="form.errors.sort"><input id="sv-s" v-model="form.sort" type="number" min="0" dir="ltr" class="field-input text-start" /></Field>
                </div>
                <Toggle v-model="form.is_active" label="متاحة للاختيار في العروض" />
            </div>
            <template #footer>
                <Btn variant="secondary" @click="open = false">إلغاء</Btn>
                <Btn :loading="form.processing" @click="save">حفظ</Btn>
            </template>
        </Modal>
    </AppLayout>
</template>

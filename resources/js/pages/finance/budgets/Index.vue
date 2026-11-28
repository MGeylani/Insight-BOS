<script setup>
import { reactive, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Plus, Building2, Pencil, Archive } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import Toggle from '@/components/Toggle.vue';
import EmptyState from '@/components/EmptyState.vue';
import FinanceTabs from '@/components/finance/FinanceTabs.vue';
import BudgetBar from '@/components/finance/BudgetBar.vue';
import { formatDate } from '@/lib/format';
import { usd } from '@/lib/crm';

const props = defineProps({ budgets: Array, costCenters: Array, filters: Object, can: Object });
const f = reactive({ ...props.filters });
watch(f, () => router.get('/finance/budgets', { scope: f.scope || undefined, current: f.current ? 1 : 0 }, { preserveState: true, replace: true }));

const centersOpen = ref(false);
const editing = ref(null);
const cForm = useForm({ name: '', code: '', is_active: true });
const editCenter = (c = null) => { editing.value = c ?? {}; cForm.defaults(c ? { name: c.name, code: c.code ?? '', is_active: c.is_active } : { name: '', code: '', is_active: true }); cForm.reset(); };
const saveCenter = () => {
    const o = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    editing.value?.id ? cForm.put(`/finance/cost-centers/${editing.value.id}`, o) : cForm.post('/finance/cost-centers', o);
};
const archiveCenter = (c) => confirm(`أرشفة «${c.name}»؟`) && router.delete(`/finance/cost-centers/${c.id}`, { preserveScroll: true });
</script>

<template>
    <AppLayout title="الميزانيات">
        <PageHeader title="الميزانيات" subtitle="المخطط مقابل الفعلي لكل مشروع أو مركز تكلفة أو للشركة، بالدولار.">
            <template #actions>
                <Btn v-if="can.centers" variant="secondary" @click="centersOpen = true"><Building2 class="size-4" /> مراكز التكلفة</Btn>
                <Btn v-if="can.create" href="/finance/budgets/create"><Plus class="size-4" /> ميزانية</Btn>
            </template>
        </PageHeader>
        <FinanceTabs />

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <select v-model="f.scope" class="field-input w-auto"><option value="">كل الميزانيات</option><option value="project">المشاريع</option><option value="center">مراكز التكلفة</option><option value="company">الشركة</option></select>
            <Toggle v-model="f.current" label="الجارية فقط" />
        </div>

        <div v-if="budgets.length" class="grid gap-4 lg:grid-cols-2">
            <Link v-for="b in budgets" :key="b.id" :href="`/finance/budgets/${b.id}`" class="card block p-5 transition hover:ring-2 hover:ring-brand/20">
                <div class="mb-3 flex items-start justify-between gap-2">
                    <div><p class="font-semibold text-navy">{{ b.name }}</p><p class="text-xs text-slate-500">{{ b.target }} · {{ formatDate(b.period_start) }} – {{ formatDate(b.period_end) }}</p></div>
                    <p class="num text-end text-sm"><span class="font-bold" :class="b.report.actual > b.report.planned ? 'text-red-600' : 'text-navy'">{{ usd(b.report.actual) }}</span><span class="block text-xs text-slate-500">من {{ usd(b.report.planned) }}</span></p>
                </div>
                <BudgetBar :percent="b.report.percent" :alert="b.alert_percent" :elapsed="b.report.elapsed" />
                <p v-if="b.report.unbudgeted" class="mt-2 text-xs text-amber-700">+ {{ usd(b.report.unbudgeted) }} خارج بنود الميزانية</p>
            </Link>
        </div>
        <EmptyState v-else title="لا توجد ميزانيات" text="أنشئ ميزانية لمشروع أو مركز تكلفة ببنود من تصنيفات المصروفات، وتصلك التنبيهات عند الاقتراب من الحد." />

        <Modal v-model="centersOpen" title="مراكز التكلفة">
            <ul class="divide-y divide-slate-100 text-sm">
                <li v-for="c in costCenters" :key="c.id" class="flex items-center justify-between py-2" :class="{ 'opacity-50': c.archived || !c.is_active }">
                    <span>{{ c.name }} <span v-if="c.code" class="num text-xs text-slate-500">{{ c.code }}</span></span>
                    <span v-if="!c.archived" class="flex gap-1">
                        <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-navy" @click="editCenter(c)"><Pencil class="size-4" /></button>
                        <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-red-600" @click="archiveCenter(c)"><Archive class="size-4" /></button>
                    </span>
                </li>
                <li v-if="!costCenters.length" class="py-3 text-slate-500">مثل: الإنتاج، التسويق الداخلي، الإدارة.</li>
            </ul>
            <div v-if="editing" class="mt-4 grid gap-3 rounded-xl bg-slate-50 p-4 sm:grid-cols-2">
                <Field label="الاسم" id="cn" :error="cForm.errors.name" required><input id="cn" v-model="cForm.name" class="field-input" /></Field>
                <Field label="الرمز" id="cc"><input id="cc" v-model="cForm.code" dir="ltr" class="field-input" /></Field>
                <Toggle v-model="cForm.is_active" label="نشط" />
                <div class="flex justify-end gap-2"><Btn size="sm" variant="secondary" @click="editing = null">تراجع</Btn><Btn size="sm" :loading="cForm.processing" @click="saveCenter">حفظ</Btn></div>
            </div>
            <template #footer><Btn variant="secondary" @click="editCenter()"><Plus class="size-4" /> مركز</Btn><Btn @click="centersOpen = false">إغلاق</Btn></template>
        </Modal>
    </AppLayout>
</template>

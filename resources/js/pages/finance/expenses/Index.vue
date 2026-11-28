<script setup>
import { reactive, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Plus, Search, Tags, Pencil, Archive } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import Toggle from '@/components/Toggle.vue';
import Pagination from '@/components/Pagination.vue';
import EmptyState from '@/components/EmptyState.vue';
import FinanceTabs from '@/components/finance/FinanceTabs.vue';
import { formatDate } from '@/lib/format';
import { money, usd } from '@/lib/crm';
import { expenseStatusTone, settlementTone } from '@/lib/finance';

const props = defineProps({
    expenses: Object, summary: Object, filters: Object, categories: Array, people: Array,
    statuses: Object, paidBy: Object, settlements: Object, kinds: Object, can: Object,
});
const f = reactive({ search: '', status: '', category: '', user: '', paid_by: '', from: '', to: '', mine: '', decide: '', ...props.filters });
let t;
watch(f, () => {
    clearTimeout(t);
    t = setTimeout(() => router.get('/finance/expenses', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300);
});
const quick = (patch) => Object.assign(f, { status: '', decide: '', mine: '' }, patch);

const catOpen = ref(false);
const catEdit = ref(null);
const catForm = useForm({ name: '', kind: 'direct', is_active: true, sort: 0 });
const editCat = (c = null) => {
    catEdit.value = c ?? {};
    catForm.defaults(c ? { name: c.name, kind: c.kind, is_active: c.is_active, sort: c.sort ?? 0 } : { name: '', kind: 'direct', is_active: true, sort: 0 });
    catForm.reset();
};
const saveCat = () => {
    const opts = { preserveScroll: true, onSuccess: () => (catEdit.value = null) };
    catEdit.value?.id ? catForm.put(`/finance/expense-categories/${catEdit.value.id}`, opts) : catForm.post('/finance/expense-categories', opts);
};
const archiveCat = (c) => confirm(`أرشفة «${c.name}»؟ تبقى على المصروفات القديمة.`) && router.delete(`/finance/expense-categories/${c.id}`, { preserveScroll: true });
</script>

<template>
    <AppLayout title="المصروفات">
        <PageHeader title="المصروفات" subtitle="المصروفات المقدّمة واعتمادها، وما دفعه الموظفون وما يُستحق للموردين.">
            <template #actions>
                <Btn v-if="can.categories" variant="secondary" @click="catOpen = true"><Tags class="size-4" /> التصنيفات</Btn>
                <Btn v-if="can.create" href="/finance/expenses/create"><Plus class="size-4" /> مصروف</Btn>
            </template>
        </PageHeader>
        <FinanceTabs />

        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="card p-5"><p class="text-xs text-slate-500">المعتمد هذا الشهر</p><p class="num mt-1 text-2xl font-bold text-navy">{{ usd(summary.month_usd) }}</p></div>
            <button type="button" class="card p-5 text-start hover:ring-2 hover:ring-amber-200" @click="summary.to_decide ? quick({ decide: '1' }) : quick({ status: 'pending' })">
                <p class="text-xs text-slate-500">{{ summary.to_decide ? 'بانتظار اعتمادك' : 'بانتظار الاعتماد' }}</p>
                <p class="num mt-1 text-2xl font-bold text-amber-600">{{ summary.to_decide || summary.pending }}</p>
            </button>
            <Link v-if="summary.to_reimburse_usd !== null" href="/finance/reimbursements" class="card p-5 hover:ring-2 hover:ring-orchid/30">
                <p class="text-xs text-slate-500">مستحق للموظفين</p><p class="num mt-1 text-2xl font-bold text-plum">{{ usd(summary.to_reimburse_usd) }}</p>
            </Link>
            <Link v-if="summary.vendors_owed_usd !== null" href="/finance/vendors" class="card p-5 hover:ring-2 hover:ring-red-100">
                <p class="text-xs text-slate-500">مستحق للموردين</p><p class="num mt-1 text-2xl font-bold text-red-600">{{ usd(summary.vendors_owed_usd) }}</p>
            </Link>
        </div>

        <div class="card overflow-hidden">
            <div class="flex flex-wrap gap-2 border-b border-slate-100 p-4">
                <div class="relative min-w-56 flex-1 sm:max-w-xs">
                    <Search class="absolute inset-y-0 start-3 my-auto size-4 text-slate-400" />
                    <input v-model="f.search" type="search" placeholder="الرقم، البيان، رقم فاتورة المورد" class="field-input ps-9" />
                </div>
                <select v-model="f.status" class="field-input w-auto">
                    <option value="">كل الحالات</option>
                    <option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option>
                    <option value="to_reimburse">بانتظار التعويض</option>
                    <option value="unpaid_bills">فواتير موردين غير مسدّدة</option>
                </select>
                <select v-model="f.category" class="field-input w-auto"><option value="">كل التصنيفات</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                <select v-model="f.paid_by" class="field-input w-auto"><option value="">كل طرق الدفع</option><option v-for="(l, k) in paidBy" :key="k" :value="k">{{ l }}</option></select>
                <select v-if="people.length" v-model="f.user" class="field-input w-auto"><option value="">كل الموظفين</option><option v-for="p in people" :key="p.id" :value="p.id">{{ p.name }}</option></select>
                <input v-model="f.from" type="date" dir="ltr" class="field-input w-auto" title="من" />
                <input v-model="f.to" type="date" dir="ltr" class="field-input w-auto" title="إلى" />
            </div>
            <div class="overflow-x-auto">
                <table v-if="expenses.data.length" class="tbl">
                    <thead><tr><th>الرقم</th><th>التاريخ</th><th>البيان</th><th>التصنيف</th><th>المبلغ</th><th>الموظف / المورد</th><th>الحالة</th></tr></thead>
                    <tbody>
                        <tr v-for="e in expenses.data" :key="e.id" class="cursor-pointer" @click="router.visit(`/finance/expenses/${e.id}`)">
                            <td class="num whitespace-nowrap font-semibold text-navy">{{ e.number }}</td>
                            <td class="whitespace-nowrap text-slate-600">{{ formatDate(e.date) }}</td>
                            <td><p class="font-medium text-slate-800">{{ e.description }}</p><p v-if="e.project" class="text-xs text-slate-500">{{ e.project.number }} · {{ e.project.name }}</p></td>
                            <td class="text-slate-600">{{ e.category?.name }}</td>
                            <td class="whitespace-nowrap"><p class="num font-semibold">{{ money(e.amount, e.currency) }}</p><p v-if="e.currency !== 'USD'" class="num text-xs text-slate-500">{{ usd(e.amount_usd) }}</p></td>
                            <td class="text-slate-600"><p>{{ e.user?.name }}</p><p v-if="e.vendor" class="text-xs">{{ e.vendor.name }}</p></td>
                            <td class="space-x-1 whitespace-nowrap">
                                <Badge :tone="expenseStatusTone[e.status]">{{ statuses[e.status] }}</Badge>
                                <Badge v-if="e.settlement" :tone="settlementTone[e.settlement]">{{ settlements[e.settlement] }}</Badge>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-else title="لا توجد مصروفات" text="سجّل المصروف وأرفق صورة الإيصال ليعتمده المسؤول.">
                    <Btn v-if="can.create" href="/finance/expenses/create"><Plus class="size-4" /> مصروف</Btn>
                </EmptyState>
            </div>
            <Pagination :links="expenses.links" />
        </div>

        <Modal v-model="catOpen" title="تصنيفات المصروفات" wide>
            <p class="mb-3 text-sm text-slate-500">«تكلفة مباشرة» تُحسب على المشاريع والعملاء في الربحية، و«مصاريف عامة» على تشغيل الوكالة.</p>
            <ul class="divide-y divide-slate-100 text-sm">
                <li v-for="c in categories" :key="c.id" class="flex items-center justify-between py-2" :class="{ 'opacity-50': !c.is_active }">
                    <span>{{ c.name }} <span class="text-xs text-slate-500">· {{ kinds[c.kind] }}</span></span>
                    <span class="flex gap-1">
                        <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-navy" @click="editCat(c)"><Pencil class="size-4" /></button>
                        <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-red-600" @click="archiveCat(c)"><Archive class="size-4" /></button>
                    </span>
                </li>
            </ul>
            <div v-if="catEdit" class="mt-4 grid gap-4 rounded-xl bg-slate-50 p-4 sm:grid-cols-2">
                <Field label="الاسم" id="cn" :error="catForm.errors.name" required><input id="cn" v-model="catForm.name" class="field-input" /></Field>
                <Field label="النوع" id="ck"><select id="ck" v-model="catForm.kind" class="field-input"><option v-for="(l, k) in kinds" :key="k" :value="k">{{ l }}</option></select></Field>
                <Toggle v-model="catForm.is_active" label="نشط" />
                <div class="flex justify-end gap-2"><Btn variant="secondary" size="sm" @click="catEdit = null">تراجع</Btn><Btn size="sm" :loading="catForm.processing" @click="saveCat">حفظ</Btn></div>
            </div>
            <template #footer><Btn variant="secondary" @click="editCat()"><Plus class="size-4" /> تصنيف</Btn><Btn @click="catOpen = false">إغلاق</Btn></template>
        </Modal>
    </AppLayout>
</template>

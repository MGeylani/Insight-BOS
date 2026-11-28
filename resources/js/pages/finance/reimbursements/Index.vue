<script setup>
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { HandCoins, Undo2 } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import EmptyState from '@/components/EmptyState.vue';
import FinanceTabs from '@/components/finance/FinanceTabs.vue';
import { formatDate } from '@/lib/format';
import { money } from '@/lib/crm';

const props = defineProps({ groups: Array, recent: Array, accounts: Array, can: Object });
const page = usePage();
const errors = computed(() => Object.values(page.props.errors ?? {}));
const today = new Date().toISOString().slice(0, 10);
const paying = ref(null);
const form = useForm({ user_id: '', account_id: '', date: today, expense_ids: [], reference: '' });
const accountsFor = computed(() => props.accounts.filter((a) => a.currency === paying.value?.currency));
const openPay = (g) => {
    paying.value = g;
    form.user_id = g.user.id;
    form.expense_ids = g.expenses.map((e) => e.id);
    form.account_id = accountsFor.value[0]?.id ?? '';
};
const total = computed(() => paying.value?.expenses.filter((e) => form.expense_ids.includes(e.id)).reduce((s, e) => s + e.amount, 0) ?? 0);
const pay = () => form.post('/finance/reimbursements', { preserveScroll: true, onSuccess: () => (paying.value = null) });
const reverse = (r) => confirm('عكس هذا التعويض؟ تعود المصروفات بانتظار التعويض.') && router.post(`/finance/reimbursements/${r.id}/reverse`, {}, { preserveScroll: true });
</script>

<template>
    <AppLayout title="تعويض الموظفين">
        <PageHeader title="تعويض الموظفين" subtitle="المصروفات المعتمدة التي دفعها الموظفون من مالهم وتنتظر الصرف." />
        <FinanceTabs />
        <div v-if="errors.length" class="mb-6 rounded-2xl bg-red-50 p-4 text-sm text-red-800"><p v-for="x in errors" :key="x">{{ x }}</p></div>

        <div v-if="groups.length" class="mb-8 grid gap-4 lg:grid-cols-2">
            <div v-for="g in groups" :key="g.user.id + g.currency" class="card p-5">
                <div class="mb-3 flex items-center justify-between">
                    <div><p class="font-semibold text-navy">{{ g.user.name }}</p><p class="num text-xl font-bold text-plum">{{ money(g.total, g.currency) }}</p></div>
                    <Btn v-if="can.pay" @click="openPay(g)"><HandCoins class="size-4" /> صرف</Btn>
                </div>
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="e in g.expenses" :key="e.id" class="flex justify-between py-1.5">
                        <a :href="`/finance/expenses/${e.id}`" class="text-slate-700 hover:underline"><span class="num text-xs text-slate-500">{{ e.number }}</span> {{ e.description }}</a>
                        <span class="num">{{ money(e.amount, g.currency) }}</span>
                    </li>
                </ul>
            </div>
        </div>
        <EmptyState v-else title="لا شيء بانتظار التعويض" text="المصروفات التي يدفعها الموظف من ماله تظهر هنا بعد اعتمادها." class="mb-8" />

        <div v-if="recent.length" class="card overflow-hidden">
            <h3 class="border-b border-slate-100 px-5 py-3 font-semibold text-navy">آخر التعويضات</h3>
            <table class="tbl">
                <thead><tr><th>التاريخ</th><th>الموظف</th><th>المبلغ</th><th>من حساب</th><th>المصروفات</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="r in recent" :key="r.id" :class="{ 'opacity-50 line-through': r.status === 'reversed' }">
                        <td class="text-slate-600">{{ formatDate(r.date) }}</td><td>{{ r.user }}</td><td class="num font-semibold">{{ money(r.amount, r.currency) }}</td>
                        <td class="text-slate-600">{{ r.account }}</td><td class="num text-slate-600">{{ r.expenses_count }}</td>
                        <td><Btn v-if="can.reverse && r.status === 'posted'" size="sm" variant="ghost" @click="reverse(r)"><Undo2 class="size-3.5" /></Btn><Badge v-if="r.status === 'reversed'" tone="slate">معكوس</Badge></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal :model-value="!!paying" :title="`صرف تعويض لـ ${paying?.user.name ?? ''}`" @update:model-value="(v) => !v && (paying = null)">
            <template v-if="paying">
                <ul class="mb-4 space-y-1 text-sm">
                    <li v-for="e in paying.expenses" :key="e.id"><label class="flex items-center gap-2"><input v-model="form.expense_ids" type="checkbox" :value="e.id" /> <span class="num text-xs text-slate-500">{{ e.number }}</span> {{ e.description }} <span class="num ms-auto">{{ money(e.amount, paying.currency) }}</span></label></li>
                </ul>
                <div class="grid gap-4 sm:grid-cols-2">
                    <Field :label="`من حساب (${paying.currency})`" id="a" :error="form.errors.account_id" required>
                        <select id="a" v-model="form.account_id" class="field-input"><option value="">—</option><option v-for="a in accountsFor" :key="a.id" :value="a.id">{{ a.name }}</option></select>
                    </Field>
                    <Field label="التاريخ" id="d" :error="form.errors.date"><input id="d" v-model="form.date" type="date" :max="today" dir="ltr" class="field-input" /></Field>
                    <Field label="المرجع" id="r" class="sm:col-span-2"><input id="r" v-model="form.reference" class="field-input" /></Field>
                </div>
                <p class="mt-4 text-sm">المجموع: <span class="num font-bold text-navy">{{ money(total, paying.currency) }}</span></p>
            </template>
            <template #footer><Btn variant="secondary" @click="paying = null">تراجع</Btn><Btn :loading="form.processing" :disabled="!form.expense_ids.length" @click="pay">صرف</Btn></template>
        </Modal>
    </AppLayout>
</template>

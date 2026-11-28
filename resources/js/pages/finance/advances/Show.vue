<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Plus, Undo2, Ban } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import FinanceTabs from '@/components/finance/FinanceTabs.vue';
import { formatDate } from '@/lib/format';
import { money } from '@/lib/crm';
import { expenseStatusTone } from '@/lib/finance';

const props = defineProps({ advance: Object, expenses: Array, returns: Array, accounts: Array, kinds: Object, statuses: Object, can: Object });
const a = computed(() => props.advance);
const page = usePage();
const errors = computed(() => Object.values(page.props.errors ?? {}));
const today = new Date().toISOString().slice(0, 10);
const returning = ref(false);
const form = useForm({ amount: '', account_id: '', date: today, notes: '' });
const openReturn = () => { form.amount = a.value.balance; returning.value = true; };
const submit = () => form.post(`/finance/advances/${a.value.id}/return`, { preserveScroll: true, onSuccess: () => (returning.value = false) });
const reverse = () => confirm('عكس العهدة؟ تُلغى ويعود المبلغ إلى الحساب.') && router.post(`/finance/advances/${a.value.id}/reverse`, {}, { preserveScroll: true });
</script>

<template>
    <AppLayout :title="a.number">
        <PageHeader :title="`${kinds[a.kind]} ${a.number}`" :subtitle="`${a.user?.name} · ${formatDate(a.date)} · من ${a.account}`">
            <template #actions>
                <Btn v-if="can.spend && a.balance > 0" variant="secondary" href="/finance/expenses/create"><Plus class="size-4" /> مصروف من العهدة</Btn>
                <Btn v-if="can.return && a.balance > 0" @click="openReturn"><Undo2 class="size-4" /> إرجاع / خصم</Btn>
                <Btn v-if="can.reverse" variant="ghost" class="!text-red-600" @click="reverse"><Ban class="size-4" /> عكس</Btn>
            </template>
        </PageHeader>
        <FinanceTabs />
        <div v-if="errors.length" class="mb-6 rounded-2xl bg-red-50 p-4 text-sm text-red-800"><p v-for="x in errors" :key="x">{{ x }}</p></div>
        <div v-if="a.status === 'reversed'" class="mb-6 rounded-2xl bg-slate-100 p-4 text-sm text-slate-700"><strong>معكوسة</strong></div>

        <div class="mb-6 grid gap-4 sm:grid-cols-4">
            <div class="card p-4"><p class="text-xs text-slate-500">المبلغ</p><p class="num mt-1 text-lg font-bold">{{ money(a.amount, a.currency) }}</p></div>
            <div class="card p-4"><p class="text-xs text-slate-500">المصروفات المعتمدة</p><p class="num mt-1 text-lg font-bold text-navy">{{ money(a.spent, a.currency) }}</p><p v-if="a.pending" class="text-xs text-amber-600">+ {{ money(a.pending, a.currency) }} بانتظار الاعتماد</p></div>
            <div class="card p-4"><p class="text-xs text-slate-500">المرتجع</p><p class="num mt-1 text-lg font-bold">{{ money(a.returned, a.currency) }}</p></div>
            <div class="card p-4"><p class="text-xs text-slate-500">المتبقي مع الموظف</p><p class="num mt-1 text-lg font-bold text-plum">{{ money(a.balance, a.currency) }}</p></div>
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="card overflow-hidden xl:col-span-2">
                <h3 class="border-b border-slate-100 px-5 py-3 font-semibold text-navy">المصروفات من العهدة</h3>
                <table class="tbl">
                    <thead><tr><th>الرقم</th><th>التاريخ</th><th>البيان</th><th>المبلغ</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="e in expenses" :key="e.id" class="cursor-pointer" @click="router.visit(`/finance/expenses/${e.id}`)">
                            <td class="num font-semibold text-navy">{{ e.number }}</td><td class="text-slate-600">{{ formatDate(e.date) }}</td>
                            <td>{{ e.description }}<p class="text-xs text-slate-500">{{ e.category?.name }}</p></td>
                            <td class="num">{{ money(e.amount, e.currency) }}</td>
                            <td><Badge :tone="expenseStatusTone[e.status]">{{ statuses[e.status] }}</Badge></td>
                        </tr>
                        <tr v-if="!expenses.length"><td colspan="5" class="py-8 text-center text-slate-500">لا مصروفات بعد.</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="card p-5">
                <h3 class="mb-3 font-semibold text-navy">المرتجع والخصم</h3>
                <ul class="space-y-2 text-sm">
                    <li v-for="r in returns" :key="r.id" class="flex justify-between"><span>{{ formatDate(r.date) }} · {{ r.account ?? 'خصم' }}<span v-if="r.notes" class="block text-xs text-slate-500">{{ r.notes }}</span></span><span class="num font-semibold">{{ money(r.amount, a.currency) }}</span></li>
                    <li v-if="!returns.length" class="text-slate-500">لا شيء بعد.</li>
                </ul>
                <p v-if="a.notes" class="mt-4 whitespace-pre-line border-t border-slate-100 pt-3 text-sm text-slate-600">{{ a.notes }}</p>
            </div>
        </div>

        <Modal v-model="returning" title="إرجاع المتبقي أو خصمه">
            <div class="grid gap-4 sm:grid-cols-2">
                <Field label="المبلغ" id="m" :error="form.errors.amount" required><input id="m" v-model="form.amount" type="number" step="0.01" min="0" dir="ltr" class="field-input" /></Field>
                <Field label="التاريخ" id="d" :error="form.errors.date"><input id="d" v-model="form.date" type="date" :max="today" dir="ltr" class="field-input" /></Field>
                <Field label="إلى حساب" id="c" :error="form.errors.account_id" hint="فارغ = خصم من الراتب أو تسوية دون نقد" class="sm:col-span-2">
                    <select id="c" v-model="form.account_id" class="field-input"><option value="">— خصم / بلا نقد —</option><option v-for="x in accounts" :key="x.id" :value="x.id">{{ x.name }} ({{ x.currency }})</option></select>
                </Field>
                <Field label="ملاحظة" id="n" class="sm:col-span-2"><input id="n" v-model="form.notes" class="field-input" /></Field>
            </div>
            <template #footer><Btn variant="secondary" @click="returning = false">تراجع</Btn><Btn :loading="form.processing" @click="submit">تسجيل</Btn></template>
        </Modal>
    </AppLayout>
</template>

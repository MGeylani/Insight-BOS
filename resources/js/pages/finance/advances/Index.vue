<script setup>
import { computed, ref, watch, reactive } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
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

const props = defineProps({ advances: Array, kinds: Object, people: Array, accounts: Array, filters: Object, can: Object });
const f = reactive({ user: '', ...props.filters });
watch(f, () => router.get('/finance/advances', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }));
const open = computed(() => props.advances.filter((a) => a.status === 'posted' && a.balance > 0));
const today = new Date().toISOString().slice(0, 10);

const giving = ref(false);
const form = useForm({ user_id: '', kind: 'petty_cash', account_id: '', date: today, amount: '', notes: '' });
const give = () => form.post('/finance/advances', { onSuccess: () => (giving.value = false) });
</script>

<template>
    <AppLayout title="السلف والعهد">
        <PageHeader title="السلف والعهد" subtitle="العهد النثرية تُسوّى بالمصروفات، والسلف على الراتب تُسترد. المتبقي = المبلغ − المصروفات المعتمدة − المرتجع.">
            <template #actions><Btn v-if="can.give" @click="giving = true"><Plus class="size-4" /> عهدة / سلفة</Btn></template>
        </PageHeader>
        <FinanceTabs />

        <div class="card overflow-hidden">
            <div v-if="people.length" class="border-b border-slate-100 p-4">
                <select v-model="f.user" class="field-input w-auto"><option value="">كل الموظفين</option><option v-for="p in people" :key="p.id" :value="p.id">{{ p.name }}</option></select>
            </div>
            <div class="overflow-x-auto">
                <table v-if="advances.length" class="tbl">
                    <thead><tr><th>الرقم</th><th>الموظف</th><th>النوع</th><th>التاريخ</th><th>المبلغ</th><th>المصروف</th><th>المرتجع</th><th>المتبقي</th></tr></thead>
                    <tbody>
                        <tr v-for="a in advances" :key="a.id" class="cursor-pointer" :class="{ 'opacity-50 line-through': a.status === 'reversed' }" @click="router.visit(`/finance/advances/${a.id}`)">
                            <td class="num font-semibold text-navy">{{ a.number }}</td>
                            <td>{{ a.user?.name }}</td>
                            <td class="text-slate-600">{{ kinds[a.kind] }}</td>
                            <td class="whitespace-nowrap text-slate-600">{{ formatDate(a.date) }}</td>
                            <td class="num whitespace-nowrap">{{ money(a.amount, a.currency) }}</td>
                            <td class="num">{{ a.spent ? money(a.spent, a.currency) : '—' }}<p v-if="a.pending" class="text-xs text-amber-600">+ {{ money(a.pending, a.currency) }} بانتظار</p></td>
                            <td class="num">{{ a.returned ? money(a.returned, a.currency) : '—' }}</td>
                            <td class="num font-semibold"><span v-if="a.balance > 0" class="text-plum">{{ money(a.balance, a.currency) }}</span><Badge v-else-if="a.status === 'posted'" tone="green">مسوّاة</Badge></td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-else title="لا عهد ولا سلف" text="سجّل المبلغ المسلَّم للموظف، ثم يقدّم مصروفاته «من عهدة» حتى تُسوّى." />
            </div>
        </div>
        <p v-if="open.length" class="mt-3 text-xs text-slate-500">مفتوحة: {{ open.length }}</p>

        <Modal v-model="giving" title="تسليم عهدة أو سلفة">
            <div class="grid gap-4 sm:grid-cols-2">
                <Field label="الموظف" id="u" :error="form.errors.user_id" required><select id="u" v-model="form.user_id" class="field-input"><option value="">—</option><option v-for="p in people" :key="p.id" :value="p.id">{{ p.name }}</option></select></Field>
                <Field label="النوع" id="k"><select id="k" v-model="form.kind" class="field-input"><option v-for="(l, k) in kinds" :key="k" :value="k">{{ l }}</option></select></Field>
                <Field label="من حساب" id="a" :error="form.errors.account_id" required><select id="a" v-model="form.account_id" class="field-input"><option value="">—</option><option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }} ({{ a.currency }})</option></select></Field>
                <Field label="المبلغ" id="m" :error="form.errors.amount" required><input id="m" v-model="form.amount" type="number" step="0.01" min="0" dir="ltr" class="field-input" /></Field>
                <Field label="التاريخ" id="d" :error="form.errors.date"><input id="d" v-model="form.date" type="date" :max="today" dir="ltr" class="field-input" /></Field>
                <Field label="ملاحظات" id="n" class="sm:col-span-2"><textarea id="n" v-model="form.notes" rows="2" class="field-input" /></Field>
            </div>
            <template #footer><Btn variant="secondary" @click="giving = false">تراجع</Btn><Btn :loading="form.processing" @click="give">تسجيل</Btn></template>
        </Modal>
    </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Pencil, Check, X, Ban, AlertTriangle, Building2, Briefcase, UserRound, CalendarDays, Landmark, Truck, Wallet2 } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import FinanceTabs from '@/components/finance/FinanceTabs.vue';
import AttachmentsPanel from '@/components/AttachmentsPanel.vue';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { money, usd } from '@/lib/crm';
import { expenseStatusTone, settlementTone } from '@/lib/finance';

const props = defineProps({ expense: Object, files: Object, statuses: Object, paidBy: Object, settlements: Object, receiptRequired: Boolean, log: Array });
const page = usePage();
const e = computed(() => props.expense);
const errors = computed(() => Object.values(page.props.errors ?? {}));
const busy = ref(false);
const approve = () => router.post(`/finance/expenses/${e.value.id}/approve`, {}, { preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false) });

const rejecting = ref(false);
const rejectForm = useForm({ reason: '' });
const reject = () => rejectForm.post(`/finance/expenses/${e.value.id}/reject`, { preserveScroll: true, onSuccess: () => (rejecting.value = false) });

const cancelling = ref(false);
const cancelForm = useForm({ reason: '' });
const cancel = () => cancelForm.post(`/finance/expenses/${e.value.id}/cancel`, { preserveScroll: true, onSuccess: () => (cancelling.value = false) });
</script>

<template>
    <AppLayout :title="e.number">
        <PageHeader :title="e.description" :subtitle="`مصروف ${e.number}`">
            <template #actions>
                <Btn v-if="e.can.edit" variant="secondary" :href="`/finance/expenses/${e.id}/edit`"><Pencil class="size-4" /> تعديل</Btn>
                <template v-if="e.can.decide">
                    <Btn variant="ghost" class="!text-red-600" @click="rejecting = true"><X class="size-4" /> رفض</Btn>
                    <Btn :loading="busy" :disabled="receiptRequired && !e.has_receipt" @click="approve"><Check class="size-4" /> اعتماد</Btn>
                </template>
                <Btn v-if="e.can.cancel" variant="ghost" class="!text-red-600" @click="cancelling = true"><Ban class="size-4" /> {{ e.status === 'pending' ? 'سحب' : 'إلغاء' }}</Btn>
            </template>
        </PageHeader>
        <FinanceTabs />

        <div v-if="errors.length" class="mb-6 rounded-2xl bg-red-50 p-4 text-sm text-red-800"><p v-for="x in errors" :key="x">{{ x }}</p></div>
        <div v-else-if="e.status === 'pending' && receiptRequired && !e.has_receipt" class="mb-6 flex items-center gap-2 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900">
            <AlertTriangle class="size-4 shrink-0" /> أرفق صورة الإيصال أو فاتورة المورد في «المرفقات»؛ لا يُعتمد المصروف بدونها.
        </div>
        <div v-if="e.decision_note && e.status !== 'pending'" class="mb-6 rounded-2xl p-4 text-sm" :class="e.status === 'approved' ? 'bg-emerald-50 text-emerald-900' : 'bg-slate-100 text-slate-700'">
            <strong>{{ statuses[e.status] }}</strong><template v-if="e.decider"> — {{ e.decider }}</template>: {{ e.decision_note }}
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <div class="card overflow-hidden">
                    <div class="brand-gradient h-1.5"></div>
                    <div class="grid gap-6 p-6 sm:grid-cols-3">
                        <div>
                            <p class="text-xs text-slate-500">المبلغ</p>
                            <p class="num mt-1 text-2xl font-bold text-navy">{{ money(e.amount, e.currency) }}</p>
                            <p v-if="e.currency !== 'USD'" class="num text-xs text-slate-500">{{ usd(e.amount_usd) }} · 1 USD = {{ formatNumber(e.fx_rate, 4) }} {{ e.currency }}</p>
                        </div>
                        <div><p class="text-xs text-slate-500">الحالة</p><div class="mt-2 flex flex-wrap gap-1"><Badge :tone="expenseStatusTone[e.status]">{{ statuses[e.status] }}</Badge><Badge v-if="e.settlement" :tone="settlementTone[e.settlement]">{{ settlements[e.settlement] }}</Badge></div></div>
                        <div><p class="text-xs text-slate-500">التصنيف</p><p class="mt-1 font-semibold text-slate-800">{{ e.category?.name }}</p></div>
                    </div>
                    <dl class="grid gap-3 border-t border-slate-100 p-6 text-sm sm:grid-cols-2">
                        <div class="flex items-center gap-2"><CalendarDays class="size-4 text-slate-400" /> {{ formatDate(e.date) }}</div>
                        <div class="flex items-center gap-2"><UserRound class="size-4 text-slate-400" /> {{ e.user?.name }}</div>
                        <div class="flex items-center gap-2"><Wallet2 class="size-4 text-slate-400" /> {{ paidBy[e.paid_by] }}
                            <template v-if="e.account">: {{ e.account.name }}</template>
                            <Link v-if="e.advance" :href="`/finance/advances/${e.advance.id}`" class="num text-brand hover:underline">{{ e.advance.number }}</Link>
                        </div>
                        <div v-if="e.vendor" class="flex items-center gap-2"><Truck class="size-4 text-slate-400" /> <Link :href="`/finance/vendors/${e.vendor.id}`" class="text-brand hover:underline">{{ e.vendor.name }}</Link> <span v-if="e.vendor_ref" class="num text-xs text-slate-500">#{{ e.vendor_ref }}</span></div>
                        <div v-if="e.project" class="flex items-center gap-2"><Briefcase class="size-4 text-slate-400" /> <Link :href="`/projects/${e.project.id}`" class="text-brand hover:underline">{{ e.project.number }} · {{ e.project.name }}</Link></div>
                        <div v-if="e.due_date" class="flex items-center gap-2"><CalendarDays class="size-4 text-slate-400" /> يستحق للمورد {{ formatDate(e.due_date) }} <span v-if="e.owed > 0" class="num font-semibold text-red-600">· متبقٍ {{ money(e.owed, e.currency) }}</span></div>
                        <div v-if="e.reimbursement" class="flex items-center gap-2"><Landmark class="size-4 text-slate-400" /> عُوِّض في {{ formatDate(e.reimbursement.date) }} من {{ e.reimbursement.account }}</div>
                    </dl>
                    <p v-if="e.notes" class="whitespace-pre-line border-t border-slate-100 p-6 text-sm text-slate-700">{{ e.notes }}</p>
                </div>

                <div v-if="e.payments.length" class="card p-5">
                    <h3 class="mb-3 font-semibold text-navy">دفعات المورد</h3>
                    <ul class="divide-y divide-slate-100 text-sm">
                        <li v-for="p in e.payments" :key="p.id" class="flex justify-between py-2" :class="{ 'line-through opacity-50': p.payment.status === 'reversed' }">
                            <span class="num">{{ p.payment.number }} · {{ formatDate(p.payment.date) }}</span>
                            <span class="num font-semibold">{{ money(p.amount, e.currency) }}</span>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="space-y-6">
                <AttachmentsPanel :files="files" title="الإيصال والمرفقات" />
                <div class="card p-5">
                    <h3 class="mb-3 font-semibold text-navy">السجل</h3>
                    <p class="mb-3 text-xs text-slate-500">أدخله {{ e.creator ?? '—' }}</p>
                    <ol class="relative space-y-4 border-s border-slate-200 ps-4">
                        <li v-for="l in log" :key="l.id" class="text-sm">
                            <span class="absolute -start-1.5 mt-1.5 size-3 rounded-full border-2 border-white bg-brand" />
                            <p class="text-slate-700">{{ l.description }}</p>
                            <p class="text-xs text-slate-500">{{ l.user ?? '—' }} · {{ formatDateTime(l.created_at) }}</p>
                        </li>
                    </ol>
                </div>
            </div>
        </div>

        <Modal v-model="rejecting" title="رفض المصروف">
            <Field label="السبب" id="rj" :error="rejectForm.errors.reason" required><textarea id="rj" v-model="rejectForm.reason" rows="3" class="field-input" /></Field>
            <template #footer><Btn variant="secondary" @click="rejecting = false">تراجع</Btn><Btn variant="danger" :loading="rejectForm.processing" @click="reject">رفض</Btn></template>
        </Modal>
        <Modal v-model="cancelling" :title="e.status === 'pending' ? 'سحب المصروف' : 'إلغاء المصروف'">
            <Field label="السبب" id="cx" :error="cancelForm.errors.reason || cancelForm.errors.status" :required="e.status !== 'pending'"><textarea id="cx" v-model="cancelForm.reason" rows="3" class="field-input" /></Field>
            <p class="mt-3 text-xs text-slate-500">يبقى رقم المصروف في السجل بحالة «ملغى»، ويخرج من التقارير ورصيد الحساب.</p>
            <template #footer><Btn variant="secondary" @click="cancelling = false">تراجع</Btn><Btn variant="danger" :loading="cancelForm.processing" @click="cancel">تأكيد</Btn></template>
        </Modal>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Field from '@/components/Field.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import Toggle from '@/components/Toggle.vue';
import EmptyState from '@/components/EmptyState.vue';
import { useAuth } from '@/lib/auth';
import { formatDate, formatNumber } from '@/lib/format';

const props = defineProps({ currencies: Array, current: Object, history: Array, today: String });
const { can } = useAuth();
const foreign = props.currencies.filter((c) => !c.is_base);
const base = props.currencies.find((c) => c.is_base);

const rates = useForm({
    date: props.today,
    rates: Object.fromEntries(foreign.map((c) => [c.code, props.current[c.code]?.date === props.today ? props.current[c.code].rate : ''])),
});
const saveRates = () => rates.post('/settings/currencies/rates', { preserveScroll: true });

const editOpen = ref(false);
const cur = useForm({ code: '', name: '', symbol: '', decimals: 2, is_active: true });
function editCurrency(c) {
    Object.assign(cur, { code: c.code, name: c.name, symbol: c.symbol, decimals: c.decimals, is_active: c.is_active });
    cur.clearErrors();
    editOpen.value = true;
}
const saveCurrency = () => cur.put(`/settings/currencies/${cur.code}`, { preserveScroll: true, onSuccess: () => (editOpen.value = false) });
</script>

<template>
    <AppLayout title="العملات وأسعار الصرف">
        <PageHeader title="العملات وأسعار الصرف" :subtitle="`العملة الأساسية: ${base?.name} (${base?.code}). السعر = كم وحدة تساوي دولاراً واحداً.`" />

        <div class="grid gap-6 lg:grid-cols-5">
            <form class="card p-6 lg:col-span-2" @submit.prevent="saveRates">
                <h2 class="font-semibold text-navy">إدخال سعر الصرف</h2>
                <p class="mt-1 text-sm text-slate-500">كل عملية تحفظ سعر يومها، فلا تتغير القيود السابقة عند تحديث السعر.</p>
                <div class="mt-5 space-y-4">
                    <Field label="التاريخ" id="date" :error="rates.errors.date">
                        <input id="date" v-model="rates.date" type="date" :max="today" class="field-input" />
                    </Field>
                    <Field v-for="c in foreign" :key="c.code" :label="`1 USD = ? ${c.name}`" :id="`r-${c.code}`" :error="rates.errors[`rates.${c.code}`]">
                        <div class="relative">
                            <input :id="`r-${c.code}`" v-model="rates.rates[c.code]" type="number" step="any" min="0" dir="ltr" class="field-input pr-16 text-start" :placeholder="current[c.code] ? String(current[c.code].rate) : ''" />
                            <span class="absolute inset-y-0 right-0 flex items-center px-3 text-xs font-medium text-slate-400">{{ c.code }}</span>
                        </div>
                    </Field>
                </div>
                <Btn type="submit" class="mt-6 w-full" :loading="rates.processing">حفظ الأسعار</Btn>
            </form>

            <div class="space-y-6 lg:col-span-3">
                <div class="card overflow-hidden">
                    <table class="tbl">
                        <thead><tr><th>العملة</th><th>آخر سعر</th><th>الحالة</th><th class="w-px"></th></tr></thead>
                        <tbody>
                            <tr v-for="c in currencies" :key="c.code" :class="{ 'opacity-50': !c.is_active }">
                                <td><span class="font-medium">{{ c.name }}</span> <span class="text-xs text-slate-400">{{ c.code }} · {{ c.symbol }}</span></td>
                                <td>
                                    <span v-if="c.is_base" class="text-slate-400">أساسية</span>
                                    <template v-else-if="current[c.code]"><span class="num font-semibold text-navy">{{ formatNumber(current[c.code].rate, 4) }}</span> <span class="text-xs text-slate-400">{{ formatDate(current[c.code].date) }}</span></template>
                                    <span v-else class="text-slate-400">—</span>
                                </td>
                                <td>
                                    <Badge v-if="c.is_base" tone="navy">أساسية</Badge>
                                    <Badge v-else-if="!current[c.code]" tone="red">لا يوجد سعر</Badge>
                                    <Badge v-else-if="current[c.code].stale" tone="amber">ليس سعر اليوم</Badge>
                                    <Badge v-else tone="green">محدّث</Badge>
                                </td>
                                <td><button v-if="can('settings.manage')" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-navy" @click="editCurrency(c)"><Pencil class="size-4" /></button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="card overflow-hidden">
                    <p class="border-b border-slate-100 px-5 py-3.5 font-semibold text-slate-700">سجل الأسعار (آخر 30 يوماً)</p>
                    <div class="max-h-96 overflow-y-auto">
                        <table v-if="history.length" class="tbl">
                            <thead><tr><th>التاريخ</th><th v-for="c in foreign" :key="c.code">{{ c.code }}</th><th>أدخله</th></tr></thead>
                            <tbody>
                                <tr v-for="h in history" :key="h.date">
                                    <td class="whitespace-nowrap">{{ formatDate(h.date) }}</td>
                                    <td v-for="c in foreign" :key="c.code" class="num">{{ formatNumber(h.rates[c.code], 4) }}</td>
                                    <td class="text-slate-500">{{ h.by ?? '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <EmptyState v-else title="لم تُدخل أسعار بعد" text="ابدأ بإدخال أسعار اليوم من النموذج." />
                    </div>
                </div>
            </div>
        </div>

        <Modal v-model="editOpen" :title="`تعديل العملة ${cur.code}`">
            <div class="grid gap-4 sm:grid-cols-2">
                <Field label="الاسم" id="cname" :error="cur.errors.name"><input id="cname" v-model="cur.name" class="field-input" /></Field>
                <Field label="الرمز" id="csym" :error="cur.errors.symbol"><input id="csym" v-model="cur.symbol" class="field-input" /></Field>
                <Field label="الخانات العشرية" id="cdec" :error="cur.errors.decimals"><input id="cdec" v-model="cur.decimals" type="number" min="0" max="4" class="field-input" /></Field>
                <div class="flex items-end pb-2"><Toggle v-model="cur.is_active" label="مفعّلة" /></div>
            </div>
            <template #footer>
                <Btn variant="secondary" @click="editOpen = false">إلغاء</Btn>
                <Btn :loading="cur.processing" @click="saveCurrency">حفظ</Btn>
            </template>
        </Modal>
    </AppLayout>
</template>

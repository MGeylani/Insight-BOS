<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Plus, GitPullRequestArrow, Check, X, Trash2 } from '@lucide/vue';
import Btn from '@/components/Btn.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import { formatDate, formatNumber } from '@/lib/format';
import { changeStatusTone, daysLabel } from '@/lib/projects';

const props = defineProps({
    projectId: Number, currency: String, changes: Array, canEdit: Boolean, canApprove: Boolean, seeBudget: Boolean,
    changeSources: Object, changeStatuses: Object,
});
const keep = { preserveScroll: true, preserveState: true };

const open = ref(false);
const form = useForm({ title: '', description: '', source: 'client', cost_impact: 0, days_impact: 0 });
const add = () => { form.reset(); form.clearErrors(); open.value = true; };
const save = () => form.post(`/projects/${props.projectId}/changes`, { ...keep, onSuccess: () => (open.value = false) });

const deciding = ref(null); // { change, approve }
const decision = useForm({ decision_note: '' });
const decide = (change, approve) => { deciding.value = { change, approve }; decision.reset(); decision.clearErrors(); };
const submitDecision = () => {
    const { change, approve } = deciding.value;
    decision.post(`/projects/${props.projectId}/changes/${change.id}/${approve ? 'approve' : 'reject'}`, { ...keep, onSuccess: () => (deciding.value = null) });
};
const withdraw = (c) => confirm(`سحب طلب التغيير ${c.code}؟`) && router.delete(`/projects/${props.projectId}/changes/${c.id}`, keep);
const cost = (v) => (v ? `${v > 0 ? '+' : '−'}${formatNumber(Math.abs(v))} ${props.currency}` : '—');
</script>

<template>
    <div class="card overflow-hidden">
        <div class="flex items-center gap-2 border-b border-slate-100 px-5 py-3.5">
            <GitPullRequestArrow class="size-4 text-brand" /><h3 class="me-auto font-semibold text-navy">طلبات التغيير</h3>
            <span class="hidden text-xs text-slate-500 sm:inline">الاعتماد يحدّث الميزانية وموعد التسليم تلقائياً</span>
            <Btn v-if="canEdit" size="sm" variant="secondary" @click="add"><Plus class="size-4" /> طلب تغيير</Btn>
        </div>
        <ul v-if="changes.length" class="divide-y divide-slate-100">
            <li v-for="c in changes" :key="c.id" class="flex flex-wrap items-start gap-4 px-5 py-4">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="num text-xs font-semibold text-slate-400">{{ c.code }}</span>
                        <p class="font-medium text-slate-800">{{ c.title }}</p>
                        <Badge :tone="changeStatusTone[c.status]">{{ changeStatuses[c.status] }}</Badge>
                        <Badge>{{ changeSources[c.source] }}</Badge>
                    </div>
                    <p v-if="c.description" class="mt-1 whitespace-pre-line text-sm text-slate-600">{{ c.description }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ c.creator ?? '—' }} · {{ formatDate(c.created_at) }}<template v-if="c.decided_at"> — {{ c.status === 'approved' ? 'اعتمده' : 'رفضه' }} {{ c.decider }} {{ formatDate(c.decided_at) }}</template></p>
                    <p v-if="c.decision_note" class="mt-1 text-xs" :class="c.status === 'rejected' ? 'text-red-700' : 'text-emerald-700'">{{ c.decision_note }}</p>
                </div>
                <div class="text-end text-sm">
                    <p class="num font-semibold" :class="c.cost_impact > 0 ? 'text-emerald-700' : c.cost_impact < 0 ? 'text-red-600' : 'text-slate-400'">{{ cost(c.cost_impact) }}</p>
                    <p class="text-xs text-slate-500">{{ daysLabel(c.days_impact) }}</p>
                </div>
                <div v-if="c.status === 'pending'" class="flex w-full justify-end gap-2 sm:w-auto">
                    <template v-if="canApprove">
                        <Btn size="sm" variant="secondary" class="!text-emerald-700" @click="decide(c, true)"><Check class="size-4" /> اعتماد</Btn>
                        <Btn size="sm" variant="secondary" class="!text-red-600" @click="decide(c, false)"><X class="size-4" /> رفض</Btn>
                    </template>
                    <Btn v-if="canEdit" size="sm" variant="ghost" title="سحب الطلب" @click="withdraw(c)"><Trash2 class="size-4" /></Btn>
                </div>
            </li>
        </ul>
        <p v-else class="px-5 py-8 text-center text-sm text-slate-500">لا توجد طلبات تغيير. سجّل هنا أي إضافة أو تعديل على النطاق المتفق عليه.</p>

        <Modal v-model="open" title="طلب تغيير جديد">
            <div class="grid gap-4 sm:grid-cols-2">
                <Field label="العنوان" id="cr-title" :error="form.errors.title" required class="sm:col-span-2"><input id="cr-title" v-model="form.title" class="field-input" placeholder="مثال: إضافة 4 منشورات إضافية" /></Field>
                <Field label="المصدر" id="cr-src" :error="form.errors.source" class="sm:col-span-2"><select id="cr-src" v-model="form.source" class="field-input"><option v-for="(l, k) in changeSources" :key="k" :value="k">{{ l }}</option></select></Field>
                <Field :label="`أثر التكلفة (${currency})`" id="cr-cost" :error="form.errors.cost_impact" hint="بالسالب إذا خُفّض النطاق.">
                    <input id="cr-cost" v-model.number="form.cost_impact" type="number" step="0.01" dir="ltr" class="field-input text-start" />
                </Field>
                <Field label="أثر الوقت (أيام)" id="cr-days" :error="form.errors.days_impact" hint="يُضاف إلى موعد التسليم عند الاعتماد.">
                    <input id="cr-days" v-model.number="form.days_impact" type="number" step="1" dir="ltr" class="field-input text-start" />
                </Field>
                <Field label="التفاصيل" id="cr-d" :error="form.errors.description" class="sm:col-span-2"><textarea id="cr-d" v-model="form.description" rows="3" class="field-input" /></Field>
            </div>
            <template #footer>
                <Btn variant="secondary" @click="open = false">إلغاء</Btn>
                <Btn :loading="form.processing" @click="save">تسجيل الطلب</Btn>
            </template>
        </Modal>

        <Modal :model-value="!!deciding" :title="deciding?.approve ? `اعتماد ${deciding?.change.code}` : `رفض ${deciding?.change.code}`" @update:model-value="(v) => !v && (deciding = null)">
            <div v-if="deciding" class="space-y-4">
                <p v-if="deciding.approve" class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                    <template v-if="seeBudget">تتغير الميزانية بمقدار <span class="num font-semibold">{{ cost(deciding.change.cost_impact) }}</span></template>
                    <template v-if="deciding.change.days_impact">، ويتحرك موعد التسليم <span class="num font-semibold">{{ daysLabel(deciding.change.days_impact) }}</span></template>.
                </p>
                <Field :label="deciding.approve ? 'ملاحظة (اختياري)' : 'سبب الرفض'" id="cr-note" :error="decision.errors.decision_note" :required="!deciding.approve">
                    <textarea id="cr-note" v-model="decision.decision_note" rows="3" class="field-input" />
                </Field>
            </div>
            <template #footer>
                <Btn variant="secondary" @click="deciding = null">تراجع</Btn>
                <Btn :variant="deciding?.approve ? 'primary' : 'danger'" :loading="decision.processing" @click="submitDecision">{{ deciding?.approve ? 'اعتماد' : 'رفض' }}</Btn>
            </template>
        </Modal>
    </div>
</template>

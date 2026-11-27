<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Plus, Pencil, Trash2, ShieldAlert, Bug } from '@lucide/vue';
import Btn from '@/components/Btn.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import { formatDate } from '@/lib/format';
import { riskLevelTone, riskStatusTone, severityTone, issueStatusTone } from '@/lib/projects';

const props = defineProps({
    projectId: Number, managerId: Number, risks: Array, issues: Array, canEdit: Boolean, users: Array,
    riskLevels: Object, riskCategories: Object, riskStatuses: Object, issueSeverities: Object, issueStatuses: Object,
});
const keep = { preserveScroll: true, preserveState: true };
const levelLabel = { high: 'مرتفع', medium: 'متوسط', low: 'منخفض' };

// Risks
const riskOpen = ref(false);
const riskId = ref(null);
const blankRisk = () => ({ title: '', description: '', category: 'scope', probability: 2, impact: 2, mitigation: '', owner_id: props.managerId ?? null, status: 'open' });
const rf = useForm(blankRisk());
const addRisk = () => { riskId.value = null; Object.assign(rf, blankRisk()); rf.clearErrors(); riskOpen.value = true; };
const editRisk = (r) => {
    riskId.value = r.id;
    Object.assign(rf, { title: r.title, description: r.description ?? '', category: r.category, probability: r.probability, impact: r.impact, mitigation: r.mitigation ?? '', owner_id: r.owner_id, status: r.status });
    rf.clearErrors(); riskOpen.value = true;
};
const saveRisk = () => {
    const url = `/projects/${props.projectId}/risks` + (riskId.value ? `/${riskId.value}` : '');
    const opts = { ...keep, onSuccess: () => (riskOpen.value = false) };
    riskId.value ? rf.put(url, opts) : rf.post(url, opts);
};
const destroyRisk = (r) => confirm(`حذف الخطر «${r.title}»؟`) && router.delete(`/projects/${props.projectId}/risks/${r.id}`, keep);

// Issues
const issueOpen = ref(false);
const issueId = ref(null);
const blankIssue = () => ({ title: '', description: '', severity: 'medium', owner_id: props.managerId ?? null, due_date: '', status: 'open', resolution: '' });
const isf = useForm(blankIssue());
const addIssue = () => { issueId.value = null; Object.assign(isf, blankIssue()); isf.clearErrors(); issueOpen.value = true; };
const editIssue = (i) => {
    issueId.value = i.id;
    Object.assign(isf, { title: i.title, description: i.description ?? '', severity: i.severity, owner_id: i.owner_id, due_date: i.due_date ?? '', status: i.status, resolution: i.resolution ?? '' });
    isf.clearErrors(); issueOpen.value = true;
};
const saveIssue = () => {
    const url = `/projects/${props.projectId}/issues` + (issueId.value ? `/${issueId.value}` : '');
    const opts = { ...keep, onSuccess: () => (issueOpen.value = false) };
    issueId.value ? isf.put(url, opts) : isf.post(url, opts);
};
const destroyIssue = (i) => confirm(`حذف المشكلة «${i.title}»؟`) && router.delete(`/projects/${props.projectId}/issues/${i.id}`, keep);
</script>

<template>
    <div class="space-y-6">
        <div class="card overflow-hidden">
            <div class="flex items-center gap-2 border-b border-slate-100 px-5 py-3.5">
                <ShieldAlert class="size-4 text-amber-600" /><h3 class="me-auto font-semibold text-navy">سجل المخاطر</h3>
                <span class="hidden text-xs text-slate-500 sm:inline">ما قد يحدث: احتمالية × أثر</span>
                <Btn v-if="canEdit" size="sm" variant="secondary" @click="addRisk"><Plus class="size-4" /> خطر</Btn>
            </div>
            <div class="overflow-x-auto">
                <table v-if="risks.length" class="tbl">
                    <thead><tr><th>الخطر</th><th>الدرجة</th><th>المعالجة</th><th>المسؤول</th><th>الحالة</th><th v-if="canEdit" class="w-16"></th></tr></thead>
                    <tbody>
                        <tr v-for="r in risks" :key="r.id" :class="{ 'opacity-60': ['closed', 'mitigated'].includes(r.status) }">
                            <td><p class="font-medium text-slate-800">{{ r.title }}</p><p class="text-xs text-slate-500">{{ riskCategories[r.category] }}</p></td>
                            <td class="whitespace-nowrap">
                                <Badge :tone="riskLevelTone[r.level]"><span class="num">{{ r.score }}</span> · {{ levelLabel[r.level] }}</Badge>
                                <p class="mt-1 text-xs text-slate-500">احتمال {{ riskLevels[r.probability] }} · أثر {{ riskLevels[r.impact] }}</p>
                            </td>
                            <td class="max-w-xs whitespace-pre-line text-xs text-slate-600">{{ r.mitigation || '—' }}</td>
                            <td class="text-slate-600">{{ r.owner?.name ?? '—' }}</td>
                            <td><Badge :tone="riskStatusTone[r.status]">{{ riskStatuses[r.status] }}</Badge></td>
                            <td v-if="canEdit" class="whitespace-nowrap">
                                <button type="button" class="rounded p-1 text-slate-400 hover:bg-slate-100" title="تعديل" @click="editRisk(r)"><Pencil class="size-4" /></button>
                                <button type="button" class="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600" title="حذف" @click="destroyRisk(r)"><Trash2 class="size-4" /></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="px-5 py-8 text-center text-sm text-slate-500">لا توجد مخاطر مسجلة.</p>
            </div>
        </div>

        <div class="card overflow-hidden">
            <div class="flex items-center gap-2 border-b border-slate-100 px-5 py-3.5">
                <Bug class="size-4 text-red-600" /><h3 class="me-auto font-semibold text-navy">سجل المشكلات</h3>
                <span class="hidden text-xs text-slate-500 sm:inline">ما يحدث الآن ويحتاج حلاً</span>
                <Btn v-if="canEdit" size="sm" variant="secondary" @click="addIssue"><Plus class="size-4" /> مشكلة</Btn>
            </div>
            <ul v-if="issues.length" class="divide-y divide-slate-100">
                <li v-for="i in issues" :key="i.id" class="flex items-start gap-3 px-5 py-3" :class="{ 'opacity-60': i.status === 'resolved' }">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-medium text-slate-800">{{ i.title }}</p>
                            <Badge :tone="severityTone[i.severity]">{{ issueSeverities[i.severity] }}</Badge>
                            <Badge :tone="issueStatusTone[i.status]">{{ issueStatuses[i.status] }}</Badge>
                        </div>
                        <p v-if="i.description" class="mt-1 whitespace-pre-line text-sm text-slate-600">{{ i.description }}</p>
                        <p v-if="i.resolution" class="mt-1 whitespace-pre-line text-xs text-emerald-700">الحل: {{ i.resolution }}</p>
                        <p class="mt-1 text-xs text-slate-500">
                            المسؤول: {{ i.owner?.name ?? '—' }}
                            <template v-if="i.due_date"> · <span :class="{ 'font-semibold text-red-600': i.overdue }">موعد الحل {{ formatDate(i.due_date) }}</span></template>
                            <template v-if="i.resolved_at"> · حُلّت {{ formatDate(i.resolved_at) }}</template>
                        </p>
                    </div>
                    <div v-if="canEdit" class="flex shrink-0">
                        <button type="button" class="rounded p-1 text-slate-400 hover:bg-slate-100" title="تعديل" @click="editIssue(i)"><Pencil class="size-4" /></button>
                        <button type="button" class="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600" title="حذف" @click="destroyIssue(i)"><Trash2 class="size-4" /></button>
                    </div>
                </li>
            </ul>
            <p v-else class="px-5 py-8 text-center text-sm text-slate-500">لا توجد مشكلات مسجلة.</p>
        </div>

        <Modal v-model="riskOpen" :title="riskId ? 'تعديل الخطر' : 'خطر جديد'">
            <div class="grid gap-4 sm:grid-cols-2">
                <Field label="الخطر" id="rk-title" :error="rf.errors.title" required class="sm:col-span-2"><input id="rk-title" v-model="rf.title" class="field-input" /></Field>
                <Field label="التصنيف" id="rk-cat" :error="rf.errors.category"><select id="rk-cat" v-model="rf.category" class="field-input"><option v-for="(l, k) in riskCategories" :key="k" :value="k">{{ l }}</option></select></Field>
                <Field label="الحالة" id="rk-st" :error="rf.errors.status"><select id="rk-st" v-model="rf.status" class="field-input"><option v-for="(l, k) in riskStatuses" :key="k" :value="k">{{ l }}</option></select></Field>
                <Field label="الاحتمالية" id="rk-p" :error="rf.errors.probability"><select id="rk-p" v-model.number="rf.probability" class="field-input"><option v-for="(l, k) in riskLevels" :key="k" :value="Number(k)">{{ l }}</option></select></Field>
                <Field label="الأثر" id="rk-i" :error="rf.errors.impact"><select id="rk-i" v-model.number="rf.impact" class="field-input"><option v-for="(l, k) in riskLevels" :key="k" :value="Number(k)">{{ l }}</option></select></Field>
                <Field label="المسؤول" id="rk-o" :error="rf.errors.owner_id" class="sm:col-span-2"><select id="rk-o" v-model="rf.owner_id" class="field-input"><option :value="null">—</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select></Field>
                <Field label="خطة المعالجة" id="rk-m" :error="rf.errors.mitigation" class="sm:col-span-2"><textarea id="rk-m" v-model="rf.mitigation" rows="2" class="field-input" /></Field>
                <Field label="وصف" id="rk-d" :error="rf.errors.description" class="sm:col-span-2"><textarea id="rk-d" v-model="rf.description" rows="2" class="field-input" /></Field>
            </div>
            <template #footer>
                <Btn variant="secondary" @click="riskOpen = false">إلغاء</Btn>
                <Btn :loading="rf.processing" @click="saveRisk">حفظ</Btn>
            </template>
        </Modal>

        <Modal v-model="issueOpen" :title="issueId ? 'تعديل المشكلة' : 'مشكلة جديدة'">
            <div class="grid gap-4 sm:grid-cols-2">
                <Field label="المشكلة" id="is-title" :error="isf.errors.title" required class="sm:col-span-2"><input id="is-title" v-model="isf.title" class="field-input" /></Field>
                <Field label="الخطورة" id="is-sev" :error="isf.errors.severity"><select id="is-sev" v-model="isf.severity" class="field-input"><option v-for="(l, k) in issueSeverities" :key="k" :value="k">{{ l }}</option></select></Field>
                <Field label="الحالة" id="is-st" :error="isf.errors.status"><select id="is-st" v-model="isf.status" class="field-input"><option v-for="(l, k) in issueStatuses" :key="k" :value="k">{{ l }}</option></select></Field>
                <Field label="المسؤول" id="is-o" :error="isf.errors.owner_id"><select id="is-o" v-model="isf.owner_id" class="field-input"><option :value="null">—</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select></Field>
                <Field label="موعد الحل" id="is-due" :error="isf.errors.due_date"><input id="is-due" v-model="isf.due_date" type="date" dir="ltr" class="field-input" /></Field>
                <Field label="الوصف" id="is-d" :error="isf.errors.description" class="sm:col-span-2"><textarea id="is-d" v-model="isf.description" rows="3" class="field-input" /></Field>
                <Field v-if="isf.status === 'resolved'" label="كيف حُلّت؟" id="is-r" :error="isf.errors.resolution" required class="sm:col-span-2"><textarea id="is-r" v-model="isf.resolution" rows="2" class="field-input" /></Field>
            </div>
            <template #footer>
                <Btn variant="secondary" @click="issueOpen = false">إلغاء</Btn>
                <Btn :loading="isf.processing" @click="saveIssue">حفظ</Btn>
            </template>
        </Modal>
    </div>
</template>

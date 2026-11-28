<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2, Save } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Field from '@/components/Field.vue';
import FinanceTabs from '@/components/finance/FinanceTabs.vue';
import { usd } from '@/lib/crm';

const props = defineProps({ budget: Object, projects: Array, costCenters: Array, categories: Array, people: Array, companyWide: Boolean });
const editing = computed(() => Boolean(props.budget.id));
const initialTarget = props.budget.project_id ? 'project' : props.budget.cost_center_id ? 'center' : props.companyWide ? 'company' : 'project';
const form = useForm({
    target: initialTarget,
    name: props.budget.name ?? '', project_id: props.budget.project_id ?? '', cost_center_id: props.budget.cost_center_id ?? '',
    period_start: props.budget.period_start ?? '', period_end: props.budget.period_end ?? '', alert_percent: props.budget.alert_percent ?? 90,
    owner_id: props.budget.owner_id ?? '', notes: props.budget.notes ?? '',
    lines: props.budget.lines?.length ? props.budget.lines.map((l) => ({ ...l })) : [{ kind: 'category', category_id: '', planned: '' }],
});
const used = computed(() => new Set(form.lines.map((l) => (l.kind === 'labor' ? 'labor' : 'c' + l.category_id))));
const total = computed(() => form.lines.reduce((s, l) => s + (Number(l.planned) || 0), 0));
const addLine = (kind = 'category') => form.lines.push({ kind, category_id: '', planned: '' });
const save = () => {
    const t = form.transform((d) => ({
        ...d,
        project_id: d.target === 'project' ? d.project_id || null : null,
        cost_center_id: d.target === 'center' ? d.cost_center_id || null : null,
        owner_id: d.owner_id || null,
        lines: d.lines.map((l) => ({ kind: l.kind, category_id: l.kind === 'labor' ? null : l.category_id || null, planned: l.planned || 0 })),
    }));
    editing.value ? t.put(`/finance/budgets/${props.budget.id}`) : t.post('/finance/budgets');
};
</script>

<template>
    <AppLayout :title="editing ? 'تعديل الميزانية' : 'ميزانية جديدة'">
        <PageHeader :title="editing ? 'تعديل الميزانية' : 'ميزانية جديدة'" subtitle="المبالغ بالدولار. الفعلي يُحسب من المصروفات المعتمدة (وساعات العمل) داخل الفترة." />
        <FinanceTabs />

        <div class="card max-w-4xl p-6">
            <div class="grid gap-5 sm:grid-cols-2">
                <Field label="الاسم" id="n" :error="form.errors.name" required class="sm:col-span-2"><input id="n" v-model="form.name" class="field-input" placeholder="ميزانية حملة رمضان، تشغيل 2027…" /></Field>
                <div class="sm:col-span-2">
                    <p class="field-label mb-2">الميزانية لـ</p>
                    <div class="flex flex-wrap gap-2">
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2 text-sm" :class="form.target === 'project' ? 'border-brand bg-brand/5 font-semibold text-navy' : 'border-slate-200'"><input v-model="form.target" type="radio" value="project" class="sr-only" /> مشروع</label>
                        <label v-if="costCenters.length" class="flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2 text-sm" :class="form.target === 'center' ? 'border-brand bg-brand/5 font-semibold text-navy' : 'border-slate-200'"><input v-model="form.target" type="radio" value="center" class="sr-only" /> مركز تكلفة</label>
                        <label v-if="companyWide" class="flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2 text-sm" :class="form.target === 'company' ? 'border-brand bg-brand/5 font-semibold text-navy' : 'border-slate-200'"><input v-model="form.target" type="radio" value="company" class="sr-only" /> الشركة كلها</label>
                    </div>
                </div>
                <Field v-if="form.target === 'project'" label="المشروع" id="p" :error="form.errors.project_id" required class="sm:col-span-2"><select id="p" v-model="form.project_id" class="field-input"><option value="">—</option><option v-for="p in projects" :key="p.id" :value="p.id">{{ p.number }} · {{ p.name }}</option></select></Field>
                <Field v-if="form.target === 'center'" label="مركز التكلفة" id="c" :error="form.errors.cost_center_id" required class="sm:col-span-2"><select id="c" v-model="form.cost_center_id" class="field-input"><option value="">—</option><option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.name }}</option></select></Field>
                <Field label="من" id="s" :error="form.errors.period_start" required><input id="s" v-model="form.period_start" type="date" dir="ltr" class="field-input" /></Field>
                <Field label="إلى" id="e" :error="form.errors.period_end" required><input id="e" v-model="form.period_end" type="date" dir="ltr" class="field-input" /></Field>
                <Field label="نبّه عند (%)" id="a" :error="form.errors.alert_percent" hint="تنبيه عند بلوغ البند هذه النسبة، وآخر عند تجاوز 100%"><input id="a" v-model="form.alert_percent" type="number" min="1" max="100" dir="ltr" class="field-input" /></Field>
                <Field label="المسؤول" id="o" hint="يصله التنبيه"><select id="o" v-model="form.owner_id" class="field-input"><option value="">—</option><option v-for="p in people" :key="p.id" :value="p.id">{{ p.name }}</option></select></Field>
            </div>

            <div class="mt-6">
                <div class="mb-2 flex items-center justify-between"><p class="font-semibold text-navy">البنود</p><p class="num text-sm">المجموع: <span class="font-bold">{{ usd(total) }}</span></p></div>
                <p v-if="form.errors.lines" class="field-error mb-2">{{ form.errors.lines }}</p>
                <div v-for="(l, i) in form.lines" :key="i" class="mb-2 flex items-center gap-2">
                    <select v-if="l.kind === 'category'" v-model="l.category_id" class="field-input flex-1">
                        <option value="">— التصنيف —</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id" :disabled="used.has('c' + c.id) && Number(l.category_id) !== c.id">{{ c.name }}</option>
                    </select>
                    <span v-else class="field-input flex-1 bg-slate-50 text-slate-700">ساعات العمل (تكلفة الساعات المسجلة)</span>
                    <input v-model="l.planned" type="number" step="0.01" min="0" dir="ltr" placeholder="USD" class="field-input w-36" />
                    <button class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-red-600" @click="form.lines.splice(i, 1)"><Trash2 class="size-4" /></button>
                </div>
                <div class="flex gap-2">
                    <Btn size="sm" variant="secondary" @click="addLine()"><Plus class="size-3.5" /> بند</Btn>
                    <Btn v-if="!used.has('labor') && form.target !== 'center'" size="sm" variant="ghost" @click="addLine('labor')"><Plus class="size-3.5" /> ساعات العمل</Btn>
                </div>
            </div>
            <Field label="ملاحظات" id="nt" class="mt-5"><textarea id="nt" v-model="form.notes" rows="2" class="field-input" /></Field>
            <div class="mt-6 flex justify-end"><Btn :loading="form.processing" @click="save"><Save class="size-4" /> حفظ</Btn></div>
        </div>
    </AppLayout>
</template>

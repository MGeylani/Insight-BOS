<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2, LayoutTemplate } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Field from '@/components/Field.vue';

const props = defineProps({
    project: Object, form: Object, clients: Array, templates: Object, canBudget: Boolean,
    types: Object, statuses: Object, memberRoles: Array, users: Array, currencies: Array,
});

const form = useForm({ ...props.form, members: (props.form.members ?? []).map((m) => ({ ...m })) });
const editing = computed(() => !!props.project);
const client = computed(() => props.clients.find((c) => c.id === Number(form.client_id)));
const tpl = computed(() => (form.template ? props.templates[form.template] : null));

watch(() => form.client_id, (id, old) => {
    if (editing.value) return;
    if (old) {
        form.quote_id = null;
        form.contract_id = null;
    }
    const c = props.clients.find((x) => x.id === Number(id));
    if (c?.currency) form.currency = c.currency;
});
watch(() => form.type, (t) => {
    if (!editing.value) form.template = props.templates[t] ? t : null;
});

const addMember = () => form.members.push({ user_id: '', role: '' });
const removeMember = (i) => form.members.splice(i, 1);
const err = (k) => form.errors[k];

const submit = () => {
    if (editing.value) form.put(`/projects/${props.project.id}`);
    else form.post('/projects');
};
</script>

<template>
    <AppLayout :title="editing ? `تعديل ${project.name}` : 'مشروع جديد'">
        <PageHeader :title="editing ? 'تعديل المشروع' : 'مشروع جديد'" :subtitle="editing ? `${project.number} · ${project.name}` : 'اختر العميل ونوع المشروع؛ القالب يضيف المراحل والمخاطر المعتادة تلقائياً.'" />

        <form class="grid gap-6 xl:grid-cols-3" @submit.prevent="submit">
            <div class="space-y-6 xl:col-span-2">
                <div class="card grid gap-5 p-6 md:grid-cols-2">
                    <Field label="العميل" id="client" :error="err('client_id')" required class="md:col-span-2">
                        <select id="client" v-model="form.client_id" class="field-input" :disabled="editing">
                            <option :value="null" disabled>اختر العميل</option>
                            <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </Field>
                    <Field label="اسم المشروع" id="name" :error="err('name')" required class="md:col-span-2">
                        <input id="name" v-model="form.name" class="field-input" placeholder="مثال: هوية بصرية — منبر ULFED" />
                    </Field>
                    <Field label="نوع المشروع" id="type" :error="err('type')" required>
                        <select id="type" v-model="form.type" class="field-input"><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select>
                    </Field>
                    <Field label="الحالة" id="status" :error="err('status')" required>
                        <select id="status" v-model="form.status" class="field-input"><option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option></select>
                    </Field>
                    <Field label="تاريخ البدء" id="start" :error="err('start_date')">
                        <input id="start" v-model="form.start_date" type="date" dir="ltr" class="field-input" />
                    </Field>
                    <Field label="موعد التسليم" id="due" :error="err('due_date')">
                        <input id="due" v-model="form.due_date" type="date" dir="ltr" class="field-input" />
                    </Field>
                    <Field label="وصف ونطاق العمل" id="desc" :error="err('description')" class="md:col-span-2">
                        <textarea id="desc" v-model="form.description" rows="5" class="field-input" />
                    </Field>
                </div>

                <div v-if="!editing" class="card p-6">
                    <div class="flex flex-wrap items-center gap-3">
                        <LayoutTemplate class="size-5 text-plum" />
                        <div class="me-auto">
                            <p class="font-semibold text-navy">قالب المشروع</p>
                            <p class="text-xs text-slate-500">مواعيد المراحل تُحسب من تاريخ البدء، ويمكن تعديلها بعد الإنشاء.</p>
                        </div>
                        <select v-model="form.template" class="field-input w-auto">
                            <option :value="null">بدون قالب</option>
                            <option v-for="(t, k) in templates" :key="k" :value="k">{{ types[k] }}</option>
                        </select>
                    </div>
                    <p v-if="tpl" class="mt-3 rounded-xl bg-orchid/10 px-4 py-2.5 text-sm text-plum">
                        يضيف <span class="num">{{ tpl.milestones }}</span> مراحل و<span class="num">{{ tpl.risks }}</span> مخاطر معتادة، على مدى <span class="num">{{ tpl.days }}</span> يوماً تقريباً.
                    </p>
                </div>

                <div class="card p-6">
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <p class="font-semibold text-navy">فريق المشروع</p>
                            <p class="text-xs text-slate-500">من له صلاحية «المُسند إليه» يرى المشاريع التي هو عضو فيها.</p>
                        </div>
                        <Btn variant="secondary" size="sm" @click="addMember"><Plus class="size-4" /> عضو</Btn>
                    </div>
                    <div v-if="form.members.length" class="space-y-2">
                        <div v-for="(m, i) in form.members" :key="i" class="flex gap-2">
                            <select v-model="m.user_id" class="field-input flex-1">
                                <option value="" disabled>اختر الشخص</option>
                                <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                            </select>
                            <input v-model="m.role" list="member-roles" placeholder="الدور" class="field-input w-44" />
                            <Btn variant="ghost" size="sm" title="إزالة" @click="removeMember(i)"><Trash2 class="size-4" /></Btn>
                        </div>
                        <p v-for="(e, k) in form.errors" v-show="k.startsWith('members.')" :key="k" class="field-error">{{ e }}</p>
                    </div>
                    <p v-else class="text-sm text-slate-500">لم يُضف أحد بعد. مدير المشروع لا يحتاج إضافته هنا.</p>
                    <datalist id="member-roles"><option v-for="r in memberRoles" :key="r" :value="r" /></datalist>
                </div>
            </div>

            <div class="space-y-6">
                <div class="card space-y-5 p-6">
                    <Field label="مدير المشروع" id="manager" :error="err('manager_id')">
                        <select id="manager" v-model="form.manager_id" class="field-input">
                            <option :value="null">—</option>
                            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                    </Field>
                    <div class="grid grid-cols-3 gap-3">
                        <Field label="العملة" id="cur" :error="err('currency')">
                            <select id="cur" v-model="form.currency" class="field-input" :disabled="!canBudget"><option v-for="c in currencies" :key="c" :value="c">{{ c }}</option></select>
                        </Field>
                        <Field label="الميزانية (قيمة المشروع)" id="budget" :error="err('budget')" class="col-span-2">
                            <input id="budget" v-model="form.budget" type="number" step="0.01" min="0" dir="ltr" class="field-input text-start" :disabled="!canBudget" />
                        </Field>
                    </div>
                    <p v-if="!canBudget" class="text-xs text-slate-500">الميزانية يحددها من لديه صلاحية «إدارة الميزانية»؛ تُنسخ تلقائياً من العرض أو العقد المرتبط.</p>
                </div>

                <div class="card space-y-5 p-6">
                    <Field label="عرض السعر المقبول" id="quote" :error="err('quote_id')">
                        <select id="quote" v-model="form.quote_id" class="field-input" :disabled="!client">
                            <option :value="null">—</option>
                            <option v-for="q in client?.quotes ?? []" :key="q.id" :value="q.id">{{ q.number }} · {{ q.title }}</option>
                        </select>
                    </Field>
                    <Field label="العقد" id="contract" :error="err('contract_id')" hint="العقود الصادرة أو السارية غير المرتبطة بمشروع آخر.">
                        <select id="contract" v-model="form.contract_id" class="field-input" :disabled="!client">
                            <option :value="null">—</option>
                            <option v-for="c in client?.contracts ?? []" :key="c.id" :value="c.id">{{ c.number }} · {{ c.title }}</option>
                        </select>
                    </Field>
                </div>

                <div class="flex gap-2">
                    <Btn type="submit" :loading="form.processing">{{ editing ? 'حفظ' : 'إنشاء المشروع' }}</Btn>
                    <Btn variant="secondary" :href="editing ? `/projects/${project.id}` : '/projects'">إلغاء</Btn>
                </div>
            </div>
        </form>
    </AppLayout>
</template>

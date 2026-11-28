<script setup>
// Create / edit a custom field definition.
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import Btn from '@/components/Btn.vue';
import { fieldTypeLabels } from '@/lib/tasks';

const open = defineModel({ type: Boolean, default: false });
const props = defineProps({
    field: Object, types: { type: Array, default: () => [] }, scopes: { type: Array, default: () => [{ type: 'all', id: null, label: 'كل المهام' }] },
    numericFields: { type: Array, default: () => [] }, currencies: { type: Array, default: () => ['USD', 'TRY', 'EUR'] },
});
const form = useForm({ name: '', type: 'text', options: {}, scope: '', type_ids: [] });
const choices = ref([]);
const palette = ['#0082F6', '#10b981', '#f59e0b', '#ef4444', '#864690', '#00027C', '#64748b', '#0e7490'];
watch(open, (v) => {
    if (!v) return;
    form.clearErrors();
    const f = props.field;
    form.name = f?.name ?? '';
    form.type = f?.type ?? 'text';
    form.options = { ...(f?.options ?? {}) };
    form.type_ids = [...(f?.type_ids ?? [])];
    form.scope = f ? `${f.scope_type}:${f.scope_id ?? ''}` : `${props.scopes[0].type}:${props.scopes[0].id ?? ''}`;
    choices.value = (f?.options?.choices ?? []).map((c) => ({ ...c }));
    if (!choices.value.length) choices.value = [{ id: '', label: '', color: palette[0] }];
});
const hasChoices = computed(() => ['dropdown', 'labels'].includes(form.type));
const insertRef = (id) => { form.options.formula = `${form.options.formula ?? ''} {${id}}`.trim(); };
function save() {
    const [scope_type, scope_id] = form.scope.split(':');
    const payload = (d) => ({
        name: d.name, type: props.field ? undefined : d.type, scope_type, scope_id: scope_id ? Number(scope_id) : null, type_ids: d.type_ids,
        options: hasChoices.value ? { choices: choices.value } : d.options,
    });
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    if (props.field) form.transform(payload).put(`/tasks/fields/${props.field.id}`, opts);
    else form.transform(payload).post('/tasks/fields', opts);
}
</script>

<template>
    <Modal v-model="open" :title="field ? `تعديل الحقل: ${field.name}` : 'حقل مخصص جديد'">
        <div class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <Field label="الاسم" id="cf-n" :error="form.errors.name" required><input id="cf-n" v-model="form.name" class="field-input" /></Field>
                <Field label="النوع" id="cf-t" :error="form.errors.type">
                    <select id="cf-t" v-model="form.type" class="field-input" :disabled="!!field"><option v-for="(l, k) in fieldTypeLabels" :key="k" :value="k">{{ l }}</option></select>
                </Field>
            </div>
            <div v-if="hasChoices" class="space-y-2">
                <p class="text-sm font-medium text-slate-700">الخيارات</p>
                <div v-for="(c, i) in choices" :key="i" class="flex items-center gap-2">
                    <input v-model="c.color" type="color" class="h-9 w-10 shrink-0 cursor-pointer rounded-lg border border-slate-200 bg-white p-1" />
                    <input v-model="c.label" class="field-input flex-1" placeholder="اسم الخيار" />
                    <button type="button" class="rounded p-1 text-slate-400 hover:text-red-600" @click="choices.splice(i, 1)"><Trash2 class="size-4" /></button>
                </div>
                <Btn size="sm" variant="secondary" @click="choices.push({ id: '', label: '', color: palette[choices.length % palette.length] })"><Plus class="size-4" /> خيار</Btn>
            </div>
            <Field v-if="form.type === 'money'" label="العملة" id="cf-c"><select id="cf-c" v-model="form.options.currency" class="field-input"><option v-for="c in currencies" :key="c" :value="c">{{ c }}</option></select></Field>
            <Field v-if="form.type === 'number'" label="المنازل العشرية" id="cf-d"><input id="cf-d" v-model.number="form.options.decimals" type="number" min="0" max="4" class="field-input" /></Field>
            <Field v-if="form.type === 'rating'" label="أعلى تقييم" id="cf-r"><input id="cf-r" v-model.number="form.options.max" type="number" min="3" max="10" class="field-input" /></Field>
            <div v-if="form.type === 'formula'" class="space-y-2">
                <Field label="الصيغة" id="cf-f" hint="+ - * / وأقواس. انقر على حقل لإضافته؛ {estimate} = التقدير بالساعات.">
                    <input id="cf-f" v-model="form.options.formula" dir="ltr" class="field-input font-mono text-start" placeholder="{12} * {13}" />
                </Field>
                <div class="flex flex-wrap gap-1">
                    <button type="button" class="rounded-md bg-slate-100 px-2 py-1 text-xs hover:bg-slate-200" @click="form.options.formula = `${form.options.formula ?? ''} {estimate}`.trim()">التقدير (ساعة)</button>
                    <button v-for="n in numericFields.filter((x) => x.id !== field?.id)" :key="n.id" type="button" class="rounded-md bg-slate-100 px-2 py-1 text-xs hover:bg-slate-200" @click="insertRef(n.id)">{{ n.name }} <span class="text-slate-400">{{ '{' + n.id + '}' }}</span></button>
                </div>
            </div>
            <Field label="أين يظهر" id="cf-s"><select id="cf-s" v-model="form.scope" class="field-input"><option v-for="s in scopes" :key="`${s.type}:${s.id ?? ''}`" :value="`${s.type}:${s.id ?? ''}`">{{ s.label }}</option></select></Field>
            <div v-if="types.length">
                <p class="mb-1 text-sm font-medium text-slate-700">أنواع المهام <span class="text-xs font-normal text-slate-500">(بلا اختيار = كل الأنواع)</span></p>
                <div class="flex flex-wrap gap-2">
                    <label v-for="t in types" :key="t.id" class="flex items-center gap-1.5 rounded-lg border border-slate-200 px-2 py-1 text-sm"><input v-model="form.type_ids" type="checkbox" :value="t.id" class="rounded" /> {{ t.name }}</label>
                </div>
            </div>
            <p v-for="(e, k) in form.errors" :key="k" class="field-error">{{ e }}</p>
        </div>
        <template #footer><Btn variant="secondary" @click="open = false">إلغاء</Btn><Btn :loading="form.processing" @click="save">حفظ</Btn></template>
    </Modal>
</template>

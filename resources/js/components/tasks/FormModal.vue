<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { ArrowUp, ArrowDown, Trash2, Plus } from '@lucide/vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import Btn from '@/components/Btn.vue';
import PeopleTokens from './PeopleTokens.vue';

const open = defineModel({ type: Boolean, default: false });
const props = defineProps({ form: Object, lists: Array, standard: Object, users: Array, tags: Array, types: Array, priorities: Object, storage: Boolean });
const f = ref({});
const errors = ref({});
const busy = ref(false);
const listFields = ref([]);
watch(open, (v) => {
    if (!v) return;
    errors.value = {};
    const src = props.form ?? {};
    f.value = JSON.parse(JSON.stringify({
        name: src.name ?? '', description: src.description ?? '', task_list_id: src.task_list_id ?? props.lists[0]?.id ?? null,
        is_public: src.is_public ?? false, confirmation: src.confirmation ?? '',
        fields: src.fields ?? [{ key: 'title', label: 'ما المطلوب؟', required: true }, { key: 'description', label: 'التفاصيل', required: false }, { key: 'due', label: 'الموعد المطلوب', required: false }],
        defaults: { assignees: [], tags: [], type_id: null, priority: '', due_in_days: null, ...(src.defaults ?? {}) },
    }));
});
watch(() => f.value.task_list_id, async (id) => {
    listFields.value = id ? await fetch(`/tasks/forms/fields?list=${id}`, { headers: { Accept: 'application/json' } }).then((r) => r.json()).catch(() => []) : [];
});
const options = computed(() => [
    ...Object.entries(props.standard).map(([k, l]) => ({ key: k, label: l })).filter((o) => o.key !== 'files' || props.storage),
    ...listFields.value.filter((x) => x.type !== 'file' || props.storage).map((x) => ({ key: `cf_${x.id}`, label: x.name })),
].filter((o) => !f.value.fields?.some((x) => x.key === o.key)));
const labelOf = (key) => props.standard[key] ?? listFields.value.find((x) => `cf_${x.id}` === key)?.name ?? key;
const adding = ref('');
const add = () => { if (adding.value) { f.value.fields.push({ key: adding.value, label: labelOf(adding.value), required: false }); adding.value = ''; } };
const move = (i, d) => { const a = f.value.fields; [a[i], a[i + d]] = [a[i + d], a[i]]; };
function save() {
    busy.value = true;
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false), onError: (e) => (errors.value = e), onFinish: () => (busy.value = false) };
    props.form ? router.put(`/tasks/forms/${props.form.id}`, f.value, opts) : router.post('/tasks/forms', f.value, opts);
}
</script>

<template>
    <Modal v-model="open" :title="form ? 'تعديل النموذج' : 'نموذج استقبال جديد'" wide>
        <div class="space-y-4 text-sm">
            <div class="grid gap-3 sm:grid-cols-2">
                <Field label="اسم النموذج" id="fm-n" :error="errors.name"><input id="fm-n" v-model="f.name" class="field-input" placeholder="مثل: طلب تصميم" /></Field>
                <Field label="تُنشأ المهام في القائمة" id="fm-l" :error="errors.task_list_id">
                    <select id="fm-l" v-model="f.task_list_id" class="field-input"><option v-for="l in lists" :key="l.id" :value="l.id">{{ l.name }}</option></select></Field>
            </div>
            <Field label="وصف يظهر أعلى النموذج" id="fm-d"><textarea id="fm-d" v-model="f.description" rows="2" class="field-input" /></Field>
            <label class="flex items-start gap-2 rounded-xl bg-slate-50 p-3"><input v-model="f.is_public" type="checkbox" class="mt-0.5 rounded" />
                <span><span class="font-medium">نموذج عام</span><span class="block text-xs text-slate-500">يُفتح من الرابط دون تسجيل دخول (للعملاء أو الموقع)، ويسأل عن اسم مقدّم الطلب. بدونه يحتاج تسجيل الدخول.</span></span></label>

            <section>
                <p class="mb-2 font-semibold text-navy">الأسئلة</p>
                <div v-for="(q, i) in f.fields" :key="q.key" class="mb-2 flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 p-2">
                    <span class="w-28 truncate text-xs text-slate-400">{{ labelOf(q.key) }}</span>
                    <input v-model="q.label" class="field-input min-w-40 flex-1 py-1.5" placeholder="نص السؤال" />
                    <input v-model="q.help" class="field-input min-w-40 flex-1 py-1.5" placeholder="توضيح (اختياري)" />
                    <label class="flex items-center gap-1 text-xs"><input v-model="q.required" type="checkbox" class="rounded" :disabled="q.key === 'title'" /> مطلوب</label>
                    <button type="button" class="rounded p-1 text-slate-400 hover:text-navy disabled:opacity-30" :disabled="i === 0" @click="move(i, -1)"><ArrowUp class="size-4" /></button>
                    <button type="button" class="rounded p-1 text-slate-400 hover:text-navy disabled:opacity-30" :disabled="i === f.fields.length - 1" @click="move(i, 1)"><ArrowDown class="size-4" /></button>
                    <button v-if="q.key !== 'title'" type="button" class="rounded p-1 text-slate-400 hover:text-red-600" @click="f.fields.splice(i, 1)"><Trash2 class="size-4" /></button>
                </div>
                <div class="flex gap-2">
                    <select v-model="adding" class="field-input w-64 py-1.5"><option value="">أضف سؤالاً…</option><option v-for="o in options" :key="o.key" :value="o.key">{{ o.label }}</option></select>
                    <Btn size="sm" variant="secondary" :disabled="!adding" @click="add"><Plus class="size-4" /> إضافة</Btn>
                </div>
                <p class="mt-1 text-xs text-slate-500">الحقول المخصصة المتاحة هي حقول القائمة المختارة.<template v-if="!storage"> رفع الملفات يحتاج ربط خدمة تخزين.</template></p>
                <p v-if="errors.fields" class="field-error">{{ errors.fields }}</p>
            </section>

            <section class="rounded-xl bg-slate-50 p-3">
                <p class="mb-2 font-semibold text-navy">قيم تلقائية للمهمة</p>
                <div class="grid gap-2 sm:grid-cols-2">
                    <label class="block"><span class="mb-1 block text-xs text-slate-500">المسؤولون (بدونهم يصل إشعار لك لتوزيعها)</span><PeopleTokens v-model="f.defaults.assignees" :users="users" :roles="false" /></label>
                    <label class="block"><span class="mb-1 block text-xs text-slate-500">النوع</span>
                        <select v-model="f.defaults.type_id" class="field-input py-1.5"><option :value="null">—</option><option v-for="t in types" :key="t.id" :value="t.id">{{ t.name }}</option></select></label>
                    <label class="block"><span class="mb-1 block text-xs text-slate-500">الأولوية</span>
                        <select v-model="f.defaults.priority" class="field-input py-1.5"><option value="">عادية</option><option v-for="(l, k) in priorities" :key="k" :value="k">{{ l }}</option></select></label>
                    <label class="block"><span class="mb-1 block text-xs text-slate-500">التسليم بعد (أيام) إن لم يُحدَّد</span><input v-model.number="f.defaults.due_in_days" type="number" min="0" class="field-input py-1.5" /></label>
                    <label class="block sm:col-span-2"><span class="mb-1 block text-xs text-slate-500">الوسوم</span>
                        <div class="flex flex-wrap gap-1.5"><label v-for="t in tags" :key="t.id" class="flex items-center gap-1 rounded-full border border-slate-200 bg-white px-2 py-0.5 text-xs">
                            <input v-model="f.defaults.tags" type="checkbox" :value="t.id" class="rounded" /> {{ t.name }}</label></div></label>
                </div>
            </section>
            <Field label="رسالة التأكيد بعد الإرسال" id="fm-c"><input id="fm-c" v-model="f.confirmation" class="field-input" placeholder="وصل طلبك، وسنتواصل معك قريباً." /></Field>
        </div>
        <template #footer><Btn variant="secondary" @click="open = false">إلغاء</Btn><Btn :loading="busy" :disabled="!lists.length" @click="save">حفظ</Btn></template>
    </Modal>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import Btn from '@/components/Btn.vue';
import PeopleTokens from './PeopleTokens.vue';

const open = defineModel({ type: Boolean, default: false });
const props = defineProps({ automation: Object, preset: Object, location: Object, triggers: Object, actionTypes: Object, statuses: Array, fields: Array, templates: Array, lists: Array, users: Array, tags: Array, priorities: Object, types: Array });
const a = ref({});
const errors = ref({});
const busy = ref(false);
watch(open, (v) => {
    if (!v) return;
    errors.value = {};
    const src = props.automation ?? props.preset ?? {};
    a.value = JSON.parse(JSON.stringify({ name: src.name ?? '', trigger: src.trigger ?? 'status_changed', trigger_config: src.trigger_config ?? {}, conditions: src.conditions ?? [], actions: src.actions ?? [{ type: 'notify', users: ['assignees'], message: '' }], is_active: src.is_active ?? true }));
});
const condFields = computed(() => [
    ['status', 'الحالة'], ['status_group', 'مجموعة الحالة'], ['priority', 'الأولوية'], ['type', 'النوع'], ['tag', 'الوسم'], ['assignee', 'المسؤول'],
    ...props.fields.map((f) => [`field:${f.id}`, f.name]),
]);
const ops = { is: 'يساوي', is_not: 'لا يساوي', empty: 'فارغ', not_empty: 'غير فارغ', gt: 'أكبر من', lt: 'أصغر من' };
const numericFields = computed(() => props.fields.filter((f) => ['number', 'money', 'percent', 'rating'].includes(f.type)));
const addAction = () => a.value.actions.push({ type: 'notify', users: ['assignees'], message: '' });
function save() {
    busy.value = true;
    const payload = { ...a.value, scope_type: props.location?.type ?? 'all', scope_id: props.location?.id ?? null };
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false), onError: (e) => (errors.value = e), onFinish: () => (busy.value = false) };
    props.automation ? router.put(`/tasks/automations/${props.automation.id}`, payload, opts) : router.post('/tasks/automations', payload, opts);
}
const groups = { not_started: 'لم تبدأ', active: 'نشطة', done: 'مكتملة', closed: 'مغلقة' };
</script>

<template>
    <Modal v-model="open" :title="automation ? 'تعديل الأتمتة' : 'أتمتة جديدة'" wide>
        <div class="space-y-5 text-sm">
            <Field label="الاسم" id="au-n" :error="errors.name"><input id="au-n" v-model="a.name" class="field-input" placeholder="مثل: مراجعة داخلية ← مهمة للمدير الإبداعي" /></Field>

            <section class="rounded-xl bg-slate-50 p-3">
                <p class="mb-2 font-semibold text-navy">عندما (المُشغِّل)</p>
                <div class="grid gap-2 sm:grid-cols-3">
                    <select v-model="a.trigger" class="field-input py-1.5"><option v-for="(l, k) in triggers" :key="k" :value="k">{{ l }}</option></select>
                    <template v-if="a.trigger === 'status_changed'">
                        <select v-model="a.trigger_config.to" class="field-input py-1.5"><option value="">إلى أي حالة</option><option v-for="s in statuses" :key="s.id" :value="s.name">إلى «{{ s.name }}»</option></select>
                        <select v-model="a.trigger_config.to_group" class="field-input py-1.5"><option value="">أي مجموعة</option><option v-for="(l, k) in groups" :key="k" :value="k">مجموعة: {{ l }}</option></select>
                    </template>
                    <select v-if="a.trigger === 'field_changed'" v-model="a.trigger_config.field_id" class="field-input py-1.5"><option :value="null">أي حقل</option><option v-for="f in fields" :key="f.id" :value="f.id">{{ f.name }}</option></select>
                    <select v-if="a.trigger === 'assignee_added'" v-model="a.trigger_config.user_id" class="field-input py-1.5"><option :value="null">أي شخص</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select>
                    <label v-if="a.trigger === 'due_soon'" class="flex items-center gap-2">قبل <input v-model.number="a.trigger_config.hours" type="number" min="1" class="field-input w-20 py-1.5" placeholder="24" /> ساعة</label>
                    <template v-if="a.trigger === 'status_stale'">
                        <select v-model="a.trigger_config.status" class="field-input py-1.5"><option value="">أي حالة</option><option v-for="s in statuses" :key="s.id" :value="s.name">{{ s.name }}</option></select>
                        <label class="flex items-center gap-2"><input v-model.number="a.trigger_config.days" type="number" min="1" class="field-input w-20 py-1.5" placeholder="2" /> أيام بلا تغيير</label>
                    </template>
                </div>
                <p v-if="errors.trigger" class="field-error">{{ errors.trigger }}</p>
            </section>

            <section v-if="a.trigger !== 'monthly'" class="rounded-xl bg-slate-50 p-3">
                <p class="mb-2 font-semibold text-navy">إذا (شروط اختيارية)</p>
                <div v-for="(c, i) in a.conditions" :key="i" class="mb-2 flex flex-wrap items-center gap-2">
                    <select v-model="c.field" class="field-input w-40 py-1.5"><option v-for="f in condFields" :key="f[0]" :value="f[0]">{{ f[1] }}</option></select>
                    <select v-model="c.op" class="field-input w-32 py-1.5"><option v-for="(l, k) in ops" :key="k" :value="k">{{ l }}</option></select>
                    <template v-if="!['empty', 'not_empty'].includes(c.op)">
                        <select v-if="c.field === 'status'" v-model="c.value" class="field-input w-40 py-1.5"><option v-for="s in statuses" :key="s.id" :value="s.name">{{ s.name }}</option></select>
                        <select v-else-if="c.field === 'status_group'" v-model="c.value" class="field-input w-40 py-1.5"><option v-for="(l, k) in groups" :key="k" :value="k">{{ l }}</option></select>
                        <select v-else-if="c.field === 'priority'" v-model="c.value" class="field-input w-40 py-1.5"><option v-for="(l, k) in priorities" :key="k" :value="k">{{ l }}</option></select>
                        <select v-else-if="c.field === 'type'" v-model="c.value" class="field-input w-40 py-1.5"><option v-for="t in types" :key="t.id" :value="t.id">{{ t.name }}</option></select>
                        <select v-else-if="c.field === 'tag'" v-model="c.value" class="field-input w-40 py-1.5"><option v-for="t in tags" :key="t.id" :value="t.id">{{ t.name }}</option></select>
                        <select v-else-if="c.field === 'assignee'" v-model="c.value" class="field-input w-40 py-1.5"><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select>
                        <input v-else v-model="c.value" class="field-input w-40 py-1.5" placeholder="القيمة" />
                    </template>
                    <button type="button" class="rounded p-1 text-slate-400 hover:text-red-600" @click="a.conditions.splice(i, 1)"><Trash2 class="size-4" /></button>
                </div>
                <Btn size="sm" variant="secondary" @click="a.conditions.push({ field: 'status', op: 'is', value: '' })"><Plus class="size-4" /> شرط</Btn>
            </section>

            <section class="rounded-xl bg-slate-50 p-3">
                <p class="mb-2 font-semibold text-navy">إذن (الإجراءات بالترتيب)</p>
                <div v-for="(x, i) in a.actions" :key="i" class="mb-3 rounded-lg border border-slate-200 bg-white p-2">
                    <div class="flex items-center gap-2">
                        <span class="num text-xs text-slate-400">{{ i + 1 }}</span>
                        <select v-model="x.type" class="field-input w-48 py-1.5"><option v-for="(l, k) in actionTypes" :key="k" :value="k">{{ l }}</option></select>
                        <button type="button" class="ms-auto rounded p-1 text-slate-400 hover:text-red-600" @click="a.actions.splice(i, 1)"><Trash2 class="size-4" /></button>
                    </div>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        <template v-if="x.type === 'assign'"><PeopleTokens v-model="x.users" :users="users" /><select v-model="x.mode" class="field-input py-1.5"><option value="add">إضافة للمسؤولين</option><option value="replace">استبدال المسؤولين</option></select></template>
                        <select v-if="x.type === 'set_status'" v-model="x.status" class="field-input py-1.5"><option v-for="s in statuses" :key="s.id" :value="s.name">{{ s.name }}</option></select>
                        <select v-if="x.type === 'set_priority'" v-model="x.priority" class="field-input py-1.5"><option v-for="(l, k) in priorities" :key="k" :value="k">{{ l }}</option></select>
                        <template v-if="x.type === 'set_field'"><select v-model="x.field_id" class="field-input py-1.5"><option v-for="f in fields.filter((f) => f.type !== 'formula')" :key="f.id" :value="f.id">{{ f.name }}</option></select><input v-model="x.value" class="field-input py-1.5" placeholder="القيمة" /></template>
                        <template v-if="x.type === 'increment_field'"><select v-model="x.field_id" class="field-input py-1.5"><option v-for="f in numericFields" :key="f.id" :value="f.id">{{ f.name }}</option></select><input v-model.number="x.by" type="number" class="field-input py-1.5" placeholder="1" /></template>
                        <select v-if="x.type === 'add_tag'" v-model="x.tag_id" class="field-input py-1.5"><option v-for="t in tags" :key="t.id" :value="t.id">{{ t.name }}</option></select>
                        <label v-if="x.type === 'set_due'" class="flex items-center gap-2">اليوم + <input v-model.number="x.days" type="number" min="0" class="field-input w-20 py-1.5" /> يوم</label>
                        <template v-if="x.type === 'create_subtask' || x.type === 'create_task'">
                            <select v-if="x.type === 'create_task'" v-model="x.list_id" class="field-input py-1.5 sm:col-span-2"><option v-for="l in lists" :key="l.id" :value="l.id">{{ l.name }}</option></select>
                            <input v-model="x.title" class="field-input py-1.5" placeholder="العنوان ({title} = عنوان المهمة)" />
                            <PeopleTokens v-model="x.users" :users="users" />
                            <label class="flex items-center gap-2">تسليم بعد <input v-model.number="x.due_in_days" type="number" min="0" class="field-input w-20 py-1.5" /> يوم</label>
                        </template>
                        <template v-if="x.type === 'apply_template'">
                            <select v-model="x.template_id" class="field-input py-1.5"><option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }} ({{ { task: 'مهمة', list: 'قائمة', project: 'مشروع' }[t.type] }})</option></select>
                            <input v-model="x.list_name" class="field-input py-1.5" placeholder="اسم القائمة ({month} = الشهر)" />
                        </template>
                        <select v-if="x.type === 'move_to_list'" v-model="x.list_id" class="field-input py-1.5"><option v-for="l in lists" :key="l.id" :value="l.id">{{ l.name }}</option></select>
                        <template v-if="x.type === 'notify'"><PeopleTokens v-model="x.users" :users="users" /><input v-model="x.message" class="field-input py-1.5" placeholder="النص ({code} {title} {status})" /></template>
                    </div>
                </div>
                <Btn size="sm" variant="secondary" @click="addAction"><Plus class="size-4" /> إجراء</Btn>
                <p v-if="errors.actions" class="field-error">{{ errors.actions }}</p>
            </section>
            <label class="flex items-center gap-2"><input v-model="a.is_active" type="checkbox" class="rounded" /> مفعّلة</label>
        </div>
        <template #footer><Btn variant="secondary" @click="open = false">إلغاء</Btn><Btn :loading="busy" @click="save">حفظ</Btn></template>
    </Modal>
</template>

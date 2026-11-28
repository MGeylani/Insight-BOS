<script setup>
// Save the current task / list / project as a template, or create from one.
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import Btn from '@/components/Btn.vue';

const open = defineModel({ type: Boolean, default: false });
// mode: save | apply. source: {from, id, name} when saving. target: {list_id?, parent_id?, client_id?, project_id?} when applying.
const props = defineProps({ mode: String, source: Object, target: Object, templates: { type: Array, default: () => [] }, kinds: { type: Array, default: () => ['task', 'list', 'project'] } });
const kindLabel = { task: 'مهمة', list: 'قائمة', project: 'مشروع' };
const me = computed(() => usePage().props.auth?.user);
const name = ref('');
const choice = ref(null);
const listName = ref('');
const busy = ref(false);
const errors = ref({});
const available = computed(() => props.templates.filter((t) => props.kinds.includes(t.type)));
const chosen = computed(() => available.value.find((t) => t.id === choice.value));
watch(open, (v) => { if (v) { name.value = props.source?.name ?? ''; choice.value = available.value[0]?.id ?? null; listName.value = ''; errors.value = {}; } });
const opts = { preserveScroll: true, onSuccess: () => (open.value = false), onError: (e) => (errors.value = e), onFinish: () => (busy.value = false) };
function submit() {
    busy.value = true;
    if (props.mode === 'save') return router.post('/tasks/templates', { from: props.source.from, id: props.source.id, name: name.value }, opts);
    router.post(`/tasks/templates/${choice.value}/apply`, { ...props.target, name: listName.value || null }, opts);
}
const remove = (t) => confirm(`حذف القالب «${t.name}»؟`) && router.delete(`/tasks/templates/${t.id}`, { preserveScroll: true });
</script>

<template>
    <Modal v-model="open" :title="mode === 'save' ? `حفظ ${kindLabel[source?.from]} كقالب` : 'إنشاء من قالب'">
        <div class="space-y-4 text-sm">
            <template v-if="mode === 'save'">
                <p class="text-slate-500">يُحفظ الهيكل: المهام الفرعية وقوائم التحقق والحقول والوسوم والمسؤولون، والمواعيد نسبةً لتاريخ الإنشاء.</p>
                <Field label="اسم القالب" id="tpl-n" :error="errors.name"><input id="tpl-n" v-model="name" class="field-input" /></Field>
            </template>
            <template v-else>
                <p v-if="!available.length" class="text-slate-500">لا قوالب بعد. احفظ مهمة أو قائمة أو مشروعاً كقالب أولاً.</p>
                <ul v-else class="max-h-72 space-y-1 overflow-y-auto">
                    <li v-for="t in available" :key="t.id" class="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-slate-50">
                        <input :id="`tpl-${t.id}`" v-model="choice" type="radio" :value="t.id" />
                        <label :for="`tpl-${t.id}`" class="flex-1 cursor-pointer">{{ t.name }} <span class="text-xs text-slate-400">· {{ kindLabel[t.type] }}</span></label>
                        <button v-if="t.created_by === me?.id" type="button" class="rounded p-1 text-slate-300 hover:text-red-600" title="حذف القالب" @click="remove(t)"><Trash2 class="size-3.5" /></button>
                    </li>
                </ul>
                <Field v-if="chosen?.type === 'list'" label="اسم القائمة الجديدة (اختياري)" id="tpl-l"><input id="tpl-l" v-model="listName" class="field-input" placeholder="اسم القالب" /></Field>
                <p v-if="chosen?.type === 'project' && !target?.project_id" class="text-amber-700">قالب المشروع يُطبَّق من داخل مشروع.</p>
            </template>
            <p v-for="(e, k) in errors" v-show="k !== 'name'" :key="k" class="field-error">{{ e }}</p>
        </div>
        <template #footer>
            <Btn variant="secondary" @click="open = false">إلغاء</Btn>
            <Btn :loading="busy" :disabled="mode === 'apply' && (!chosen || (chosen.type === 'project' && !target?.project_id))" @click="submit">{{ mode === 'save' ? 'حفظ' : 'إنشاء' }}</Btn>
        </template>
    </Modal>
</template>

<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import Btn from '@/components/Btn.vue';
import { typeIcons } from '@/lib/tasks';

const open = defineModel({ type: Boolean, default: false });
const props = defineProps({ type: Object, icons: Array, fields: Array });
const form = useForm({ name: '', icon: 'circle-check', color: '#0082F6', is_milestone: false, is_default: false, field_ids: [] });
watch(open, (v) => {
    if (!v) return;
    form.clearErrors();
    Object.assign(form, {
        name: props.type?.name ?? '', icon: props.type?.icon ?? 'circle-check', color: props.type?.color ?? '#0082F6',
        is_milestone: props.type?.is_milestone ?? false, is_default: props.type?.is_default ?? false, field_ids: [...(props.type?.field_ids ?? [])],
    });
});
function save() {
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    props.type ? form.put(`/settings/task-types/${props.type.id}`, opts) : form.post('/settings/task-types', opts);
}
</script>

<template>
    <Modal v-model="open" :title="type ? `نوع: ${type.name}` : 'نوع مهمة جديد'">
        <div class="space-y-4">
            <div class="flex items-end gap-2">
                <input v-model="form.color" type="color" class="h-10 w-12 cursor-pointer rounded-lg border border-slate-200 bg-white p-1" />
                <Field class="flex-1" label="الاسم" id="tt-n" :error="form.errors.name"><input id="tt-n" v-model="form.name" class="field-input" /></Field>
            </div>
            <div>
                <p class="mb-1 text-sm font-medium text-slate-700">الأيقونة</p>
                <div class="flex flex-wrap gap-1">
                    <button v-for="i in icons" :key="i" type="button" class="rounded-lg border p-2" :class="form.icon === i ? 'border-brand bg-brand/10' : 'border-slate-200 hover:bg-slate-50'" @click="form.icon = i">
                        <component :is="typeIcons[i]" class="size-4" :style="{ color: form.color }" />
                    </button>
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_milestone" type="checkbox" class="rounded" /> معلم (نقطة زمنية بلا مدة، يظهر كمعيّن في Gantt)</label>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_default" type="checkbox" class="rounded" /> النوع الافتراضي للمهام الجديدة</label>
            <div v-if="fields.length">
                <p class="mb-1 text-sm font-medium text-slate-700">حقول هذا النوع <span class="text-xs font-normal text-slate-500">(تظهر فقط على مهام هذا النوع)</span></p>
                <div class="flex flex-wrap gap-2">
                    <label v-for="f in fields" :key="f.id" class="flex items-center gap-1.5 rounded-lg border border-slate-200 px-2 py-1 text-sm"><input v-model="form.field_ids" type="checkbox" :value="f.id" class="rounded" /> {{ f.name }}</label>
                </div>
            </div>
        </div>
        <template #footer><Btn variant="secondary" @click="open = false">إلغاء</Btn><Btn :loading="form.processing" @click="save">حفظ</Btn></template>
    </Modal>
</template>

<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import Btn from '@/components/Btn.vue';

// Moves an opportunity to a stage. A lost opportunity must give a reason.
const open = defineModel({ type: Boolean, default: false });
const props = defineProps({ opportunity: Object, stage: String, options: Object });
const emit = defineEmits(['done']);

const form = useForm({ stage: '', probability: null, lost_reason: '', lost_note: '' });
const def = computed(() => props.options.oppStages.find((s) => s.key === form.stage));
const isClosed = computed(() => ['won', 'lost'].includes(form.stage));

watch(open, (v) => {
    if (!v) return;
    form.clearErrors();
    form.stage = props.stage;
    form.probability = props.stage === props.opportunity?.stage ? props.opportunity.probability : def.value?.probability;
    form.lost_reason = '';
    form.lost_note = '';
});

function save() {
    form.transform((d) => ({ ...d, probability: isClosed.value ? null : d.probability }))
        .post(`/crm/opportunities/${props.opportunity.id}/stage`, { preserveScroll: true, onSuccess: () => { open.value = false; emit('done'); } });
}
</script>

<template>
    <Modal v-model="open" :title="form.stage === 'lost' ? 'تسجيل خسارة الفرصة' : form.stage === 'won' ? 'الفرصة مكسوبة' : 'تغيير المرحلة'">
        <div class="space-y-4">
            <p class="text-sm text-slate-600">{{ opportunity?.title }} ← <strong>{{ def?.label }}</strong></p>
            <Field v-if="!isClosed" label="احتمالية الفوز %" id="sm-p" hint="القيمة الافتراضية للمرحلة؛ عدّلها إن لزم." :error="form.errors.probability">
                <input id="sm-p" v-model.number="form.probability" type="number" min="0" max="100" dir="ltr" class="field-input text-start" />
            </Field>
            <template v-if="form.stage === 'lost'">
                <Field label="سبب الخسارة" id="sm-r" :error="form.errors.lost_reason" required>
                    <select id="sm-r" v-model="form.lost_reason" class="field-input"><option value="">اختر السبب…</option><option v-for="(l, k) in options.lostReasons" :key="k" :value="k">{{ l }}</option></select>
                </Field>
                <Field label="تفاصيل" id="sm-n" :error="form.errors.lost_note" :required="form.lost_reason === 'other'"><textarea id="sm-n" v-model="form.lost_note" rows="3" class="field-input" placeholder="ما الذي تعلمناه؟" /></Field>
            </template>
            <p v-if="form.stage === 'won'" class="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">مبروك! الخطوة التالية: عرض السعر والعقد ثم المشروع.</p>
        </div>
        <template #footer>
            <Btn variant="secondary" @click="open = false">إلغاء</Btn>
            <Btn :variant="form.stage === 'lost' ? 'danger' : 'primary'" :loading="form.processing" @click="save">تأكيد</Btn>
        </template>
    </Modal>
</template>

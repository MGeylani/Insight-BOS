<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Phone, Users, MessageCircle, Mail, StickyNote, CalendarClock, Check, Trash2, Bell, Plus } from '@lucide/vue';
import Field from '@/components/Field.vue';
import Btn from '@/components/Btn.vue';
import Badge from '@/components/Badge.vue';
import { formatDateTime } from '@/lib/format';
import { useAuth } from '@/lib/auth';

const props = defineProps({
    activities: Array,
    subjectType: String,
    subjectId: Number,
    options: Object,
    readonly: Boolean,
});
const { can } = useAuth();
const icons = { call: Phone, meeting: Users, whatsapp: MessageCircle, email: Mail, note: StickyNote, follow_up: CalendarClock };

const adding = ref(false);
const form = useForm({ subject_type: props.subjectType, subject_id: props.subjectId, type: 'call', title: '', body: '', due_at: '', remind_at: '', user_id: '', completed: false });

function save() {
    form.transform((d) => ({ ...d, user_id: d.user_id || null, due_at: d.due_at || null, remind_at: d.remind_at || null }))
        .post('/crm/activities', { preserveScroll: true, onSuccess: () => { form.reset('title', 'body', 'due_at', 'remind_at', 'completed'); adding.value = false; } });
}
const toggle = (a) => router.post(`/crm/activities/${a.id}/complete`, {}, { preserveScroll: true });
const remove = (a) => confirm('حذف هذا النشاط؟') && router.delete(`/crm/activities/${a.id}`, { preserveScroll: true });
</script>

<template>
    <div class="card">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3.5">
            <h3 class="font-semibold text-navy">الأنشطة والمتابعات</h3>
            <Btn v-if="!readonly" size="sm" variant="secondary" @click="adding = !adding"><Plus class="size-3.5" /> نشاط</Btn>
        </div>

        <div v-if="adding" class="space-y-3 border-b border-slate-100 bg-slate-50/60 p-5">
            <div class="flex flex-wrap gap-1.5">
                <button v-for="(l, k) in options.activityTypes" :key="k" type="button"
                    class="flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs transition"
                    :class="form.type === k ? 'border-navy bg-navy text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'"
                    @click="form.type = k">
                    <component :is="icons[k]" class="size-3.5" /> {{ l }}
                </button>
            </div>
            <Field id="a-t" :error="form.errors.title"><input id="a-t" v-model="form.title" class="field-input" placeholder="عنوان مختصر: اتصال لمناقشة العرض" /></Field>
            <textarea v-model="form.body" rows="2" class="field-input" placeholder="التفاصيل (اختياري)" />
            <div class="grid gap-3 sm:grid-cols-3">
                <Field label="موعد المتابعة" id="a-d"><input id="a-d" v-model="form.due_at" type="datetime-local" dir="ltr" class="field-input" /></Field>
                <Field label="تذكير في" id="a-r" :error="form.errors.remind_at"><input id="a-r" v-model="form.remind_at" type="datetime-local" dir="ltr" class="field-input" /></Field>
                <Field label="المسؤول" id="a-u">
                    <select id="a-u" v-model="form.user_id" class="field-input"><option value="">أنا</option><option v-for="u in options.users" :key="u.id" :value="u.id">{{ u.name }}</option></select>
                </Field>
            </div>
            <div class="flex items-center justify-between">
                <label class="flex items-center gap-2 text-sm text-slate-600"><input v-model="form.completed" type="checkbox" class="rounded" /> تم بالفعل (تسجيل فقط)</label>
                <Btn size="sm" :loading="form.processing" @click="save">حفظ النشاط</Btn>
            </div>
        </div>

        <ul v-if="activities.length" class="divide-y divide-slate-100">
            <li v-for="a in activities" :key="a.id" class="flex gap-3 px-5 py-3.5" :class="{ 'opacity-60': a.completed_at }">
                <button type="button" class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full border transition"
                    :class="a.completed_at ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-slate-300 text-slate-400 hover:border-navy hover:text-navy'"
                    :title="a.completed_at ? 'إرجاعه غير منجز' : 'تم'" :disabled="readonly" @click="toggle(a)">
                    <Check v-if="a.completed_at" class="size-4" /><component :is="icons[a.type]" v-else class="size-4" />
                </button>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="font-medium text-slate-800" :class="{ 'line-through': a.completed_at }">{{ a.title }}</p>
                        <Badge v-if="a.overdue" tone="red">متأخر</Badge>
                        <Badge v-if="a.remind_at && !a.completed_at" tone="sky"><Bell class="size-3" /> {{ formatDateTime(a.remind_at) }}</Badge>
                    </div>
                    <p v-if="a.body" class="mt-0.5 whitespace-pre-line text-sm text-slate-600">{{ a.body }}</p>
                    <p class="mt-1 text-xs text-slate-400">
                        {{ options.activityTypes[a.type] }}
                        <template v-if="a.due_at"> · الموعد {{ formatDateTime(a.due_at) }}</template>
                        · {{ a.user?.name ?? '—' }} · سُجّل {{ formatDateTime(a.created_at) }}
                    </p>
                </div>
                <button v-if="!readonly && can('crm.edit')" class="self-start rounded-lg p-1.5 text-slate-300 hover:bg-red-50 hover:text-red-600" title="حذف" @click="remove(a)"><Trash2 class="size-4" /></button>
            </li>
        </ul>
        <p v-else class="px-5 py-8 text-center text-sm text-slate-400">لا توجد أنشطة بعد.</p>
    </div>
</template>

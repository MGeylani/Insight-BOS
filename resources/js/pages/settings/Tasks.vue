<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Plus, Trash2, Palette } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Field from '@/components/Field.vue';
import Badge from '@/components/Badge.vue';
import StatusEditor from '@/components/tasks/StatusEditor.vue';

const props = defineProps({ tags: Array, templates: Array, groups: Object, internal: Object });
const keep = { preserveScroll: true };
const tagForm = useForm({ name: '', color: '#0082F6' });
const addTag = () => tagForm.post('/tasks/tags', { ...keep, onSuccess: () => tagForm.reset() });
const saveTag = (t, data) => router.put(`/settings/tags/${t.id}`, { name: t.name, color: t.color, ...data }, keep);
const removeTag = (t) => confirm(`حذف الوسم «${t.name}»؟ يُزال من ${t.tasks_count} مهمة.`) && router.delete(`/settings/tags/${t.id}`, keep);

const editing = ref(null);
const editorOpen = ref(false);
const edit = (tpl) => { editing.value = tpl; editorOpen.value = true; };

const prefix = useForm({ task_prefix: props.internal.task_prefix ?? '' });
const savePrefix = () => prefix.put('/settings/internal-prefix', keep);
</script>

<template>
    <AppLayout title="إعدادات المهام">
        <PageHeader title="إعدادات المهام" subtitle="الوسوم على مستوى الشركة، وقوالب الحالات التي تبدأ منها القوائم الجديدة" />
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="card p-6">
                <h2 class="mb-1 font-semibold text-navy">قوالب الحالات</h2>
                <p class="mb-4 text-sm text-slate-500">كل قائمة جديدة تأخذ نسخة من القالب، فتعديل القالب لا يغيّر القوائم الحالية.</p>
                <div v-for="tpl in templates" :key="tpl.id" class="mb-3 rounded-xl border border-slate-200 p-4">
                    <div class="mb-2 flex items-center gap-2">
                        <h3 class="font-semibold text-slate-800">{{ tpl.name }}</h3>
                        <Badge v-if="tpl.is_default" tone="navy">الافتراضي</Badge>
                        <Btn class="ms-auto" size="sm" variant="secondary" @click="edit(tpl)"><Palette class="size-4" /> تعديل</Btn>
                    </div>
                    <div class="flex flex-wrap gap-1">
                        <span v-for="s in tpl.statuses" :key="s.id" class="rounded-md px-2 py-0.5 text-xs font-semibold text-white" :style="{ background: s.color }">{{ s.name }}</span>
                    </div>
                </div>
            </div>

            <div class="space-y-6">
                <div class="card p-6">
                    <h2 class="mb-4 font-semibold text-navy">الوسوم</h2>
                    <div class="mb-4 flex items-end gap-2">
                        <input v-model="tagForm.color" type="color" class="h-10 w-12 cursor-pointer rounded-lg border border-slate-200 bg-white p-1" />
                        <Field class="flex-1" :error="tagForm.errors.name"><input v-model="tagForm.name" class="field-input" placeholder="وسم جديد" @keydown.enter="addTag" /></Field>
                        <Btn :loading="tagForm.processing" @click="addTag"><Plus class="size-4" /></Btn>
                    </div>
                    <ul class="divide-y divide-slate-100">
                        <li v-for="t in tags" :key="t.id" class="flex items-center gap-2 py-2">
                            <input :value="t.color" type="color" class="h-8 w-9 cursor-pointer rounded border border-slate-200 bg-white p-0.5" @change="saveTag(t, { color: $event.target.value })" />
                            <input :value="t.name" class="field-input flex-1 py-1.5" @change="saveTag(t, { name: $event.target.value })" />
                            <span class="num w-10 text-center text-xs text-slate-500" title="عدد المهام">{{ t.tasks_count }}</span>
                            <button type="button" class="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600" @click="removeTag(t)"><Trash2 class="size-4" /></button>
                        </li>
                    </ul>
                    <p v-if="!tags.length" class="text-sm text-slate-500">لا توجد وسوم بعد.</p>
                </div>
                <div class="card p-6">
                    <h2 class="mb-1 font-semibold text-navy">العمل الداخلي</h2>
                    <p class="mb-4 text-sm text-slate-500">مهام الوكالة الداخلية تتبع العميل «{{ internal.name }}». بادئة معرّفاتها:</p>
                    <div class="flex items-end gap-2">
                        <Field class="w-40" :error="prefix.errors.task_prefix"><input v-model="prefix.task_prefix" dir="ltr" maxlength="6" class="field-input uppercase" /></Field>
                        <Btn :loading="prefix.processing" @click="savePrefix">حفظ</Btn>
                    </div>
                </div>
            </div>
        </div>
        <StatusEditor v-if="editing" v-model="editorOpen" :statuses="editing.statuses" :groups="groups" :url="`/settings/status-templates/${editing.id}`"
            :title="`قالب: ${editing.name}`" with-default :is-default="editing.is_default" />
    </AppLayout>
</template>

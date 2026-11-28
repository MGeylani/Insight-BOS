<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Plus, Pencil, Trash2, Copy, Globe, Lock, ExternalLink } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Toggle from '@/components/Toggle.vue';
import FormModal from '@/components/tasks/FormModal.vue';
import { formatDateTime } from '@/lib/format';

const props = defineProps({ forms: Array, lists: Array, standard: Object, storage: Boolean, users: Array, tags: Array, types: Array, priorities: Object, groups: Object });
const open = ref(false);
const editing = ref(null);
const copied = ref(null);
const edit = (f) => { editing.value = f; open.value = true; };
const toggle = (f, v) => router.put(`/tasks/forms/${f.id}`, { is_active: v }, { preserveScroll: true });
const remove = (f) => confirm(`حذف النموذج «${f.name}»؟ المهام التي أنشأها تبقى.`) && router.delete(`/tasks/forms/${f.id}`, { preserveScroll: true });
const copy = async (f) => { await navigator.clipboard?.writeText(f.url); copied.value = f.id; setTimeout(() => (copied.value = null), 1500); };
</script>

<template>
    <AppLayout title="نماذج الاستقبال">
        <PageHeader title="نماذج الاستقبال" subtitle="نموذج طلب (داخلي أو للعميل) ينشئ مهمة في قائمة بحقولها">
            <template #actions><Btn v-if="lists.length" @click="edit(null)"><Plus class="size-4" /> نموذج</Btn></template>
        </PageHeader>
        <div class="grid gap-3 lg:grid-cols-2">
            <div v-for="f in forms" :key="f.id" class="card p-4">
                <div class="flex items-start gap-3">
                    <component :is="f.is_public ? Globe : Lock" class="mt-0.5 size-5 shrink-0" :class="f.is_public ? 'text-brand' : 'text-slate-400'" />
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-slate-800">{{ f.name }}</p>
                        <p class="text-xs text-slate-500">← {{ f.list }} · {{ f.is_public ? 'عام' : 'لأعضاء الفريق' }} · <span class="num">{{ f.submissions_count }}</span> طلب<template v-if="f.last_submitted_at"> · آخرها {{ formatDateTime(f.last_submitted_at) }}</template></p>
                        <p class="mt-1 text-xs text-slate-500">{{ f.fields.map((q) => q.label).join('، ') }}</p>
                    </div>
                    <Toggle v-if="f.can_manage" :model-value="f.is_active" @update:model-value="(v) => toggle(f, v)" />
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
                    <Btn size="sm" variant="secondary" @click="copy(f)"><Copy class="size-4" /> {{ copied === f.id ? 'نُسخ الرابط' : 'نسخ الرابط' }}</Btn>
                    <a :href="f.url" target="_blank" rel="noopener" class="inline-flex items-center gap-1 rounded-xl px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-100"><ExternalLink class="size-4" /> فتح</a>
                    <template v-if="f.can_manage">
                        <button type="button" class="ms-auto rounded p-1 text-slate-400 hover:text-brand" @click="edit(f)"><Pencil class="size-4" /></button>
                        <button type="button" class="rounded p-1 text-slate-400 hover:text-red-600" @click="remove(f)"><Trash2 class="size-4" /></button>
                    </template>
                </div>
            </div>
        </div>
        <p v-if="!forms.length" class="card p-10 text-center text-sm text-slate-500">لا نماذج بعد.<template v-if="lists.length"> أنشئ نموذج «طلب تصميم» يملؤه الفريق أو العميل فتصل الطلبات مهامَّ جاهزة.</template></p>
        <FormModal v-model="open" :form="editing" :lists="lists" :standard="standard" :users="users" :tags="tags" :types="types" :priorities="priorities" :storage="storage" />
    </AppLayout>
</template>

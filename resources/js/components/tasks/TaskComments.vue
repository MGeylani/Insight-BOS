<script setup>
import { nextTick, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { MessageSquare, Reply, Pencil, Trash2, CheckCircle2, RotateCcw, UserCheck, Send } from '@lucide/vue';
import Avatar from '@/components/Avatar.vue';
import Btn from '@/components/Btn.vue';
import Dropdown from '@/components/Dropdown.vue';
import RichEditor from '@/components/RichEditor.vue';
import RichView from '@/components/RichView.vue';
import { formatDateTime } from '@/lib/format';
import { keep } from '@/lib/tasks';

const props = defineProps({ taskId: Number, comments: Array, users: Array, uploader: Function });
const draft = ref(null);
const assignTo = ref('');
const composer = ref(null);
const busy = ref(false);
const replyFor = ref(null);
const replyDraft = ref(null);
const editing = ref(null);
const editDraft = ref(null);
const error = ref('');

function send(body, extra, done) {
    if (!body || busy.value) return;
    busy.value = true;
    router.post(`/tasks/${props.taskId}/comments`, { body, ...extra }, {
        ...keep, onSuccess: done, onFinish: () => (busy.value = false),
    });
}
const post = () => send(draft.value, { assigned_to: assignTo.value || null }, () => { draft.value = null; assignTo.value = ''; composer.value?.clear(); });
const reply = (c) => send(replyDraft.value, { parent_id: c.id }, () => { replyDraft.value = null; replyFor.value = null; });
const startEdit = (c) => { editing.value = c.id; editDraft.value = c.body; };
const saveEdit = (c) => editDraft.value && router.patch(`/tasks/comments/${c.id}`, { body: editDraft.value }, { ...keep, onSuccess: () => (editing.value = null) });
const remove = (c) => confirm('حذف التعليق' + (c.replies?.length ? ' وردوده' : '') + '؟') && router.delete(`/tasks/comments/${c.id}`, keep);
const resolve = (c, v) => router.post(`/tasks/comments/${c.id}/resolve`, { resolved: v }, keep);
const assign = (c, id) => router.post(`/tasks/comments/${c.id}/assign`, { assigned_to: id }, keep);

onMounted(async () => {
    const h = window.location.hash;
    if (h.startsWith('#comment-')) { await nextTick(); document.querySelector(h)?.scrollIntoView({ block: 'center' }); }
});
</script>

<template>
    <div class="card overflow-hidden">
        <div class="flex items-center gap-2 border-b border-slate-100 px-5 py-3">
            <MessageSquare class="size-4 text-plum" /><h3 class="font-semibold text-navy">التعليقات</h3>
            <span class="num text-xs text-slate-400">{{ comments.length }}</span>
        </div>

        <div class="space-y-5 px-5 py-4">
            <div v-for="c in comments" :id="`comment-${c.id}`" :key="c.id" class="scroll-mt-24">
                <template v-for="(m, i) in [c, ...c.replies]" :key="m.id">
                    <div :id="i ? `comment-${m.id}` : undefined" class="group flex gap-3" :class="i ? 'ms-10 mt-3' : ''">
                        <Avatar :name="m.user?.name" size="size-8 text-xs" />
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                <span class="font-semibold text-slate-800">{{ m.user?.name ?? '—' }}</span>
                                <span class="text-slate-400">{{ formatDateTime(m.created_at) }}</span>
                                <span v-if="m.edited_at" class="text-slate-400">(معدّل)</span>
                                <span v-if="m.assignee" class="flex items-center gap-1 rounded-full px-2 py-0.5" :class="m.resolved_at ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800'">
                                    <UserCheck class="size-3" /> {{ m.assignee.name }}<template v-if="m.resolved_at"> · تم الحل</template>
                                </span>
                            </div>
                            <div v-if="editing === m.id" class="mt-1">
                                <RichEditor v-model="editDraft" :users="users" :uploader="uploader" compact @submit="saveEdit(m)" @error="(e) => (error = e)" />
                                <div class="mt-2 flex justify-end gap-2"><Btn size="sm" variant="secondary" @click="editing = null">إلغاء</Btn><Btn size="sm" @click="saveEdit(m)">حفظ</Btn></div>
                            </div>
                            <RichView v-else :doc="m.body" class="mt-1" :class="m.resolved_at ? 'opacity-70' : ''" />
                            <div class="mt-1 flex flex-wrap items-center gap-3 text-xs text-slate-500">
                                <button v-if="!i" type="button" class="flex items-center gap-1 hover:text-brand" @click="replyFor = c.id; replyDraft = null"><Reply class="size-3.5" /> رد</button>
                                <template v-if="m.assignee && m.can_resolve">
                                    <button v-if="!m.resolved_at" type="button" class="flex items-center gap-1 font-semibold text-emerald-700 hover:underline" @click="resolve(m, true)"><CheckCircle2 class="size-3.5" /> تم الحل</button>
                                    <button v-else type="button" class="flex items-center gap-1 hover:text-brand" @click="resolve(m, false)"><RotateCcw class="size-3.5" /> إعادة فتح</button>
                                </template>
                                <Dropdown v-if="m.can_edit || m.can_resolve" width="w-56">
                                    <template #trigger><button type="button" class="flex items-center gap-1 opacity-0 hover:text-brand group-hover:opacity-100"><UserCheck class="size-3.5" /> {{ m.assignee ? 'تغيير الإسناد' : 'إسناد' }}</button></template>
                                    <template #default="{ close }">
                                        <button v-if="m.assignee" type="button" class="block w-full rounded-lg px-2 py-1.5 text-start text-sm text-red-600 hover:bg-red-50" @click="assign(m, null); close()">إلغاء الإسناد</button>
                                        <button v-for="u in users" :key="u.id" type="button" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50" @click="assign(m, u.id); close()"><Avatar :name="u.name" /> {{ u.name }}</button>
                                    </template>
                                </Dropdown>
                                <button v-if="m.can_edit && editing !== m.id" type="button" class="flex items-center gap-1 opacity-0 hover:text-brand group-hover:opacity-100" @click="startEdit(m)"><Pencil class="size-3.5" /> تعديل</button>
                                <button v-if="m.can_delete" type="button" class="flex items-center gap-1 opacity-0 hover:text-red-600 group-hover:opacity-100" @click="remove(m)"><Trash2 class="size-3.5" /> حذف</button>
                            </div>
                        </div>
                    </div>
                </template>
                <div v-if="replyFor === c.id" class="ms-10 mt-3">
                    <RichEditor v-model="replyDraft" :users="users" :uploader="uploader" compact :toolbar="false" placeholder="ردّك… (Ctrl+Enter للإرسال)" @submit="reply(c)" @error="(e) => (error = e)" />
                    <div class="mt-2 flex justify-end gap-2"><Btn size="sm" variant="secondary" @click="replyFor = null">إلغاء</Btn><Btn size="sm" :loading="busy" :disabled="!replyDraft" @click="reply(c)">رد</Btn></div>
                </div>
            </div>
            <p v-if="!comments.length" class="text-sm text-slate-500">لا تعليقات بعد.</p>
        </div>

        <div class="border-t border-slate-100 bg-slate-50/60 p-4">
            <RichEditor ref="composer" v-model="draft" :users="users" :uploader="uploader" compact placeholder="اكتب تعليقاً… @ للإشارة، الصق صورة مباشرة، Ctrl+Enter للإرسال" @submit="post" @error="(e) => (error = e)" />
            <p v-if="error" class="field-error">{{ error }}</p>
            <div class="mt-2 flex flex-wrap items-center justify-end gap-2">
                <select v-model="assignTo" class="field-input w-auto py-1.5 text-sm" title="التعليق المسند يظهر للشخص كبند مطلوب حتى يُحل">
                    <option value="">بلا إسناد</option><option v-for="u in users" :key="u.id" :value="u.id">إسناد إلى {{ u.name }}</option>
                </select>
                <Btn :loading="busy" :disabled="!draft" @click="post"><Send class="size-4" /> إرسال</Btn>
            </div>
        </div>
    </div>
</template>

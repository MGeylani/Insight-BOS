<script setup>
import { computed, nextTick, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { CircleCheckBig, Circle, Upload, Trash2, Clock, MapPin, MessageSquare } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Btn from '@/components/Btn.vue';
import { formatDateTime } from '@/lib/format';

const props = defineProps({ task: Object, file: Object, versions: Array, comments: Array, can: Object });
const page = usePage();
const errors = computed(() => Object.values(page.props.errors ?? {}));
const showResolved = ref(false);
const shown = computed(() => props.comments.filter((c) => showResolved.value || !c.resolved));
// Numbers follow the order of all top-level comments, so a pin keeps its number when others are resolved.
const number = (c) => props.comments.findIndex((x) => x.id === c.id) + 1;
const active = ref(null);

const draft = ref({ body: '', x: null, y: null, at_seconds: null });
const box = ref(null);
const video = ref(null);
const duration = ref(0);
const input = ref(null);
const clock = (s) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;

function pin(e) {
    if (!props.can.comment) return;
    const r = box.value.getBoundingClientRect();
    draft.value = { ...draft.value, x: +(((e.clientX - r.left) / r.width) * 100).toFixed(3), y: +(((e.clientY - r.top) / r.height) * 100).toFixed(3), at_seconds: null };
    nextTick(() => input.value?.focus());
}
function atNow() {
    video.value?.pause();
    draft.value = { ...draft.value, x: null, y: null, at_seconds: +(video.value?.currentTime ?? 0).toFixed(2) };
    nextTick(() => input.value?.focus());
}
const clearPlace = () => (draft.value = { ...draft.value, x: null, y: null, at_seconds: null });
const busy = ref(false);
function send() {
    if (!draft.value.body.trim()) return;
    busy.value = true;
    router.post(`/tasks/proof/${props.file.id}/comments`, draft.value, {
        preserveScroll: true,
        onSuccess: () => (draft.value = { body: '', x: null, y: null, at_seconds: null }),
        onFinish: () => (busy.value = false),
    });
}
const replies = ref({});
const reply = (c) => replies.value[c.id]?.trim() && router.post(`/tasks/proof/${props.file.id}/comments`, { body: replies.value[c.id], parent_id: c.id }, { preserveScroll: true, onSuccess: () => (replies.value[c.id] = '') });
const resolve = (c) => router.patch(`/tasks/proof-comments/${c.id}`, { resolved: !c.resolved }, { preserveScroll: true });
const remove = (c) => confirm('حذف التعليق؟') && router.delete(`/tasks/proof-comments/${c.id}`, { preserveScroll: true });
function focus(c) {
    active.value = c.id;
    if (c.at_seconds !== null && video.value) { video.value.currentTime = c.at_seconds; video.value.pause(); }
}
const fileInput = ref(null);
const uploadVersion = (e) => {
    const f = e.target.files[0];
    if (f) router.post(`/tasks/proof/${props.file.id}/version`, { file: f }, { forceFormData: true });
};
const latest = computed(() => props.versions[props.versions.length - 1]);
</script>

<template>
    <AppLayout :title="`تدقيق · ${file.name}`">
        <nav class="mb-3 text-xs text-slate-500"><Link :href="`/tasks/${task.id}`" class="hover:underline">{{ task.code }} {{ task.title }}</Link> ← تدقيق</nav>
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <h1 class="min-w-0 flex-1 truncate text-xl font-bold text-navy">{{ file.name }}</h1>
            <div class="flex items-center gap-1 rounded-xl bg-slate-100 p-1 text-sm">
                <Link v-for="v in versions" :key="v.id" :href="`/tasks/${task.id}/proof/${v.id}`" class="num rounded-lg px-2.5 py-1"
                    :class="v.id === file.id ? 'bg-white font-semibold text-navy shadow-sm' : 'text-slate-500 hover:text-navy'" :title="formatDateTime(v.created_at)">
                    ن{{ v.version }}<span v-if="v.open" class="ms-1 rounded-full bg-amber-100 px-1.5 text-[10px] text-amber-800">{{ v.open }}</span>
                </Link>
            </div>
            <template v-if="can.upload && latest?.id === file.id">
                <input ref="fileInput" type="file" class="hidden" :accept="file.kind === 'video' ? 'video/*' : 'image/*'" @change="uploadVersion" />
                <Btn variant="secondary" size="sm" @click="fileInput.click()"><Upload class="size-4" /> نسخة جديدة</Btn>
            </template>
        </div>
        <div v-if="errors.length" class="mb-4 rounded-2xl bg-red-50 p-4 text-sm text-red-800"><p v-for="e in errors" :key="e">{{ e }}</p></div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="card overflow-hidden bg-slate-900/95 p-3">
                <div v-if="file.kind === 'image'" ref="box" class="relative mx-auto w-fit cursor-crosshair select-none" @click="pin">
                    <img :src="file.src" :alt="file.name" class="block max-h-[75vh] max-w-full" draggable="false" />
                    <button v-for="c in shown.filter((c) => c.x !== null)" :key="c.id" type="button"
                        class="num absolute flex size-7 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-2 border-white text-xs font-bold text-white shadow-lg transition"
                        :class="[c.resolved ? 'bg-emerald-600' : 'bg-brand', active === c.id ? 'scale-125 ring-4 ring-amber-300' : '']"
                        :style="{ left: `${c.x}%`, top: `${c.y}%` }" @click.stop="focus(c)">{{ number(c) }}</button>
                    <span v-if="draft.x !== null" class="absolute size-7 -translate-x-1/2 -translate-y-1/2 animate-pulse rounded-full border-2 border-white bg-amber-500 shadow-lg" :style="{ left: `${draft.x}%`, top: `${draft.y}%` }" />
                </div>
                <template v-else>
                    <video ref="video" :src="file.src" controls preload="metadata" class="mx-auto block max-h-[70vh] w-full" @loadedmetadata="duration = $event.target.duration" />
                    <div class="relative mx-2 mt-3 h-3 rounded-full bg-white/15">
                        <button v-for="c in shown.filter((c) => c.at_seconds !== null)" :key="c.id" type="button" class="absolute top-1/2 size-3.5 -translate-x-1/2 -translate-y-1/2 rounded-full border border-white"
                            :class="c.resolved ? 'bg-emerald-500' : 'bg-amber-400'" :style="{ left: `${duration ? (c.at_seconds / duration) * 100 : 0}%` }" :title="`${number(c)} · ${clock(c.at_seconds)}`" @click="focus(c)" />
                    </div>
                    <div class="mt-3 text-center"><Btn v-if="can.comment" size="sm" variant="secondary" @click="atNow"><Clock class="size-4" /> تعليق عند اللحظة الحالية</Btn></div>
                </template>
                <p v-if="file.kind === 'image' && can.comment" class="mt-2 text-center text-xs text-white/60">انقر على أي نقطة في التصميم لإضافة تعليق عليها.</p>
            </div>

            <aside class="space-y-3">
                <div v-if="can.comment" class="card p-4">
                    <p class="mb-2 flex items-center gap-1.5 text-xs text-slate-500">
                        <template v-if="draft.x !== null"><MapPin class="size-3.5 text-amber-500" /> على النقطة المحددة</template>
                        <template v-else-if="draft.at_seconds !== null"><Clock class="size-3.5 text-amber-500" /> عند <span class="num">{{ clock(draft.at_seconds) }}</span></template>
                        <template v-else><MessageSquare class="size-3.5" /> تعليق عام على النسخة ن{{ file.version }}</template>
                        <button v-if="draft.x !== null || draft.at_seconds !== null" type="button" class="ms-auto text-slate-400 hover:text-red-600" @click="clearPlace">إلغاء التحديد</button>
                    </p>
                    <textarea ref="input" v-model="draft.body" rows="3" class="field-input text-sm" placeholder="اكتب ملاحظتك…" @keydown.ctrl.enter="send" @keydown.meta.enter="send" />
                    <div class="mt-2 flex justify-end"><Btn size="sm" :loading="busy" @click="send">إرسال</Btn></div>
                </div>

                <div class="flex items-center justify-between px-1 text-sm">
                    <span class="font-semibold text-navy">التعليقات <span class="num text-slate-400">({{ comments.filter((c) => !c.resolved).length }} مفتوحة)</span></span>
                    <label class="flex items-center gap-1.5 text-xs text-slate-500"><input v-model="showResolved" type="checkbox" class="rounded" /> المحلولة</label>
                </div>
                <div v-for="c in shown" :id="`c${c.id}`" :key="c.id" class="card p-3 text-sm transition" :class="active === c.id ? 'ring-2 ring-amber-300' : ''" @click="focus(c)">
                    <div class="flex items-start gap-2">
                        <span class="num mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white" :class="c.resolved ? 'bg-emerald-600' : 'bg-brand'">{{ number(c) }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs text-slate-500"><span class="font-medium text-slate-700">{{ c.author }}</span> · {{ formatDateTime(c.created_at) }}
                                <span v-if="c.at_seconds !== null" class="num ms-1 rounded bg-amber-50 px-1 text-amber-800">{{ clock(c.at_seconds) }}</span></p>
                            <p class="mt-1 whitespace-pre-line text-slate-800" :class="c.resolved ? 'text-slate-400 line-through' : ''">{{ c.body }}</p>
                        </div>
                        <button v-if="can.comment" type="button" class="rounded p-0.5" :class="c.resolved ? 'text-emerald-600' : 'text-slate-300 hover:text-emerald-600'" :title="c.resolved ? 'إعادة فتح' : 'تم الحل'" @click.stop="resolve(c)">
                            <CircleCheckBig v-if="c.resolved" class="size-5" /><Circle v-else class="size-5" /></button>
                        <button v-if="c.can_edit" type="button" class="rounded p-0.5 text-slate-300 hover:text-red-600" title="حذف" @click.stop="remove(c)"><Trash2 class="size-4" /></button>
                    </div>
                    <div v-for="r in c.replies" :key="r.id" class="ms-8 mt-2 border-s-2 border-slate-100 ps-2">
                        <p class="text-xs text-slate-500"><span class="font-medium text-slate-700">{{ r.author }}</span> · {{ formatDateTime(r.created_at) }}
                            <button v-if="r.can_edit" type="button" class="ms-1 text-slate-300 hover:text-red-600" @click.stop="remove(r)"><Trash2 class="inline size-3" /></button></p>
                        <p class="whitespace-pre-line text-slate-700">{{ r.body }}</p>
                    </div>
                    <input v-if="can.comment && active === c.id" v-model="replies[c.id]" class="field-input ms-8 mt-2 w-[calc(100%-2rem)] py-1 text-xs" placeholder="ردّ… (Enter)" @click.stop @keydown.enter.prevent="reply(c)" />
                </div>
                <p v-if="!shown.length" class="card p-6 text-center text-sm text-slate-500">لا تعليقات على هذه النسخة.</p>
            </aside>
        </div>
    </AppLayout>
</template>

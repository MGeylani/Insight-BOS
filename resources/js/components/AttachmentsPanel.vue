<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Paperclip, Upload, ExternalLink, RotateCw, Trash2, FileText, FileImage, FileVideo, FileArchive, File as FileIcon, AlertTriangle, CloudOff, MessageSquareDot } from '@lucide/vue';
import Badge from '@/components/Badge.vue';
import { useAuth } from '@/lib/auth';
import { formatDateTime } from '@/lib/format';

// files = { type, id, items, can_upload, policy: { connected, provider, max_mb, types } }
const props = defineProps({ files: Object, title: { type: String, default: 'الملفات' }, hint: String, flat: Boolean, proofBase: String });
const { can } = useAuth();
const page = usePage();
const input = ref(null);
const dragging = ref(false);
const progress = ref(null);
const uploadError = computed(() => Object.entries(page.props.errors ?? {}).filter(([k]) => k === 'file' || k.startsWith('files')).map(([, v]) => v)[0]);

const accept = computed(() => (props.files.policy.types.length ? props.files.policy.types.map((t) => '.' + t).join(',') : undefined));
const keep = { preserveScroll: true, preserveState: true };

function send(list) {
    const files = Array.from(list ?? []);
    if (!files.length || !props.files.can_upload || !props.files.policy.connected) return;
    const tooBig = files.find((f) => f.size > props.files.policy.max_mb * 1024 * 1024);
    if (tooBig) return alert(`«${tooBig.name}» أكبر من الحد المسموح (${props.files.policy.max_mb} ميغابايت).`);
    router.post('/attachments', { type: props.files.type, id: props.files.id, files }, {
        ...keep,
        forceFormData: true,
        onProgress: (e) => (progress.value = e?.percentage ?? null),
        onFinish: () => { progress.value = null; if (input.value) input.value.value = ''; },
    });
}
const drop = (e) => { dragging.value = false; send(e.dataTransfer?.files); };
const retry = (a) => router.post(`/attachments/${a.id}/retry`, {}, keep);
const destroy = (a) => confirm(`حذف «${a.name}»؟${a.status === 'uploaded' ? ' سيُنقل من خدمة التخزين إلى سلة المحذوفات.' : ''}`) && router.delete(`/attachments/${a.id}`, keep);

const icon = (mime = '') => (mime.startsWith('image/') ? FileImage : mime.startsWith('video/') ? FileVideo
    : /pdf|word|text|sheet|presentation/.test(mime) ? FileText : /zip|rar|7z|compressed/.test(mime) ? FileArchive : FileIcon);
const size = (b) => (b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);
</script>

<template>
    <div :class="flat ? '' : 'card overflow-hidden'">
        <div class="flex items-center gap-2 border-b border-slate-100 px-5 py-3.5">
            <Paperclip class="size-4 text-slate-400" /><h3 class="me-auto font-semibold text-navy">{{ title }}</h3>
            <span v-if="files.policy.provider" class="text-xs text-slate-500">{{ files.policy.provider }}</span>
        </div>

        <div v-if="files.can_upload" class="p-4">
            <div v-if="!files.policy.connected" class="flex items-center gap-2 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <CloudOff class="size-4 shrink-0" />
                <span>لا توجد خدمة تخزين مربوطة.<template v-if="can('settings.storage')"> اربطها من <a href="/settings/storage" class="font-semibold underline">التخزين السحابي</a>.</template><template v-else> اطلب من مدير النظام ربطها.</template></span>
            </div>
            <label v-else class="flex cursor-pointer flex-col items-center justify-center gap-1 rounded-2xl border-2 border-dashed px-4 py-6 text-center transition"
                :class="dragging ? 'border-brand bg-brand/5' : 'border-slate-200 hover:border-brand/50 hover:bg-slate-50'"
                @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="drop">
                <input ref="input" type="file" multiple class="sr-only" :accept="accept" @change="send($event.target.files)" />
                <Upload class="size-5 text-brand" />
                <span class="text-sm font-medium text-slate-700">اسحب الملفات هنا أو اضغط للاختيار</span>
                <span class="text-xs text-slate-500">حتى <span class="num">{{ files.policy.max_mb }}</span> ميغابايت<template v-if="files.policy.types.length"> · {{ files.policy.types.join('، ') }}</template><template v-if="hint"> · {{ hint }}</template></span>
                <div v-if="progress !== null" class="mt-2 h-1.5 w-48 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-brand transition-all" :style="{ width: progress + '%' }" /></div>
            </label>
            <p v-if="uploadError" class="field-error">{{ uploadError }}</p>
        </div>

        <ul v-if="files.items.length" class="divide-y divide-slate-100">
            <li v-for="a in files.items" :key="a.id" class="flex items-center gap-3 px-5 py-3">
                <component :is="icon(a.mime)" class="size-5 shrink-0 text-slate-400" />
                <div class="min-w-0 flex-1">
                    <a v-if="a.status === 'uploaded'" :href="`/attachments/${a.id}/open`" target="_blank" rel="noopener" class="block truncate text-sm font-medium text-slate-800 hover:text-brand hover:underline">{{ a.name }}</a>
                    <p v-else class="truncate text-sm font-medium text-slate-600">{{ a.name }}</p>
                    <p class="text-xs text-slate-500"><span class="num">{{ size(a.size) }}</span> · {{ a.uploader ?? '—' }} · {{ formatDateTime(a.created_at) }}</p>
                    <p v-if="a.error" class="mt-0.5 flex items-start gap-1 text-xs text-red-700"><AlertTriangle class="mt-0.5 size-3 shrink-0" />{{ a.error }}</p>
                </div>
                <span v-if="a.version > 1" class="num rounded bg-slate-100 px-1.5 text-xs text-slate-500">ن{{ a.version }}</span>
                <Link v-if="proofBase && a.proofable" :href="`${proofBase}/${a.id}`" class="flex items-center gap-1 rounded-lg px-2 py-1 text-xs text-brand hover:bg-brand/10" title="مراجعة بتعليقات على الصورة أو الفيديو"><MessageSquareDot class="size-4" /> تدقيق</Link>
                <Badge v-if="a.status === 'failed'" tone="red">فشل الرفع</Badge>
                <Badge v-else-if="a.status === 'pending'" tone="amber">قيد الرفع</Badge>
                <a v-if="a.status === 'uploaded'" :href="`/attachments/${a.id}/open`" target="_blank" rel="noopener" class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-brand" title="فتح في الخدمة"><ExternalLink class="size-4" /></a>
                <button v-if="a.can_retry" type="button" class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-brand" title="إعادة المحاولة" @click="retry(a)"><RotateCw class="size-4" /></button>
                <button v-if="a.can_delete" type="button" class="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600" title="حذف" @click="destroy(a)"><Trash2 class="size-4" /></button>
            </li>
        </ul>
        <p v-else-if="!files.can_upload" class="px-5 py-6 text-center text-sm text-slate-500">لا توجد ملفات.</p>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Cloud, CircleCheck, Info, Link2, Unlink, Activity, RotateCw, Copy, KeyRound, AlertTriangle } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Field from '@/components/Field.vue';
import Badge from '@/components/Badge.vue';
import { formatDateTime } from '@/lib/format';

const props = defineProps({ providers: Object, connection: Object, failed: Number });

const form = useForm({
    provider: props.connection?.provider ?? 'google_drive',
    max_upload_mb: props.connection?.max_upload_mb ?? 100,
    allowed_types: props.connection?.allowed_types ?? [],
    root_folder_name: props.connection?.root_folder_name ?? 'Insight BOS',
});
const typesText = computed({
    get: () => form.allowed_types.join(', '),
    set: (v) => (form.allowed_types = v.split(/[\s,،]+/).map((x) => x.trim().toLowerCase().replace(/^\./, '')).filter(Boolean)),
});
const statusTone = { connected: 'green', disconnected: 'amber', error: 'red' };
const statusLabel = { connected: 'متصل', disconnected: 'غير متصل بعد', error: 'خطأ في الاتصال' };

const active = computed(() => (props.connection ? props.providers[props.connection.provider] : null));
const dirtyProvider = computed(() => props.connection?.provider !== form.provider);
const linked = computed(() => props.connection?.status === 'connected');

const dav = useForm({ base_url: props.connection?.webdav?.base_url ?? '', username: props.connection?.webdav?.username ?? '', password: '' });
const saveDav = () => dav.post('/settings/storage/webdav', { preserveScroll: true, onSuccess: () => dav.reset('password') });

const post = (url, msg) => (!msg || confirm(msg)) && router.post(url, {}, { preserveScroll: true });
const copy = (t) => navigator.clipboard?.writeText(t);
</script>

<template>
    <AppLayout title="التخزين السحابي">
        <PageHeader title="التخزين السحابي" subtitle="مزود واحد نشط للنظام كله. الملفات تُرفع من داخل النظام إلى الخدمة المختارة، في مجلد لكل عميل ومشروع، ولا تبقى على خادم الموقع." />

        <!-- Current connection -->
        <div v-if="connection" class="card mb-6 p-5">
            <div class="flex flex-wrap items-center gap-4">
                <span class="flex size-11 items-center justify-center rounded-xl bg-navy-50 text-navy"><Cloud class="size-5" /></span>
                <div class="min-w-0 flex-1">
                    <p class="font-semibold text-slate-800">{{ active?.label }}</p>
                    <p class="text-sm text-slate-500">{{ connection.account_label || 'لم يُربط حساب بعد' }}<template v-if="connection.root_folder_name"> · المجلد الرئيسي «{{ connection.root_folder_name }}»</template></p>
                    <p v-if="connection.last_checked_at" class="text-xs text-slate-400">آخر فحص {{ formatDateTime(connection.last_checked_at) }}</p>
                </div>
                <Badge :tone="statusTone[connection.status]">{{ statusLabel[connection.status] }}</Badge>
                <template v-if="active?.auth === 'oauth'">
                    <a v-if="active.configured" :href="`/settings/storage/connect/${connection.provider}`" class="inline-flex items-center gap-2 rounded-xl bg-navy px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-navy-700">
                        <Link2 class="size-4" /> {{ linked ? 'إعادة الربط' : 'ربط الحساب' }}
                    </a>
                </template>
                <Btn v-if="connection.status !== 'disconnected'" variant="secondary" @click="post('/settings/storage/test')"><Activity class="size-4" /> اختبار الاتصال</Btn>
                <Btn v-if="connection.status !== 'disconnected'" variant="ghost" class="!text-red-600" @click="post('/settings/storage/disconnect', 'فصل الحساب؟ الملفات المرفوعة تبقى في الخدمة، لكن لا يمكن رفع ملفات جديدة حتى يُعاد الربط.')"><Unlink class="size-4" /> فصل</Btn>
            </div>
            <p v-if="connection.last_error" class="mt-3 flex items-start gap-2 rounded-xl bg-red-50 px-4 py-2.5 text-sm text-red-800"><AlertTriangle class="mt-0.5 size-4 shrink-0" />{{ connection.last_error }}</p>

            <!-- OAuth app keys missing -->
            <div v-if="active?.auth === 'oauth' && !active.configured" class="mt-4 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">
                <p class="flex items-center gap-2 font-semibold"><KeyRound class="size-4" /> مفاتيح تطبيق {{ active.label }} غير موجودة على الخادم</p>
                <p class="mt-1">أضف هذه القيم إلى ملف <code dir="ltr">.env</code> على الخادم ثم نفّذ <code dir="ltr">php artisan config:cache</code>:</p>
                <p class="num mt-1" dir="ltr">{{ active.env.join(' · ') }}</p>
            </div>
            <div v-if="active?.auth === 'oauth'" class="mt-4 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                <span>رابط إعادة التوجيه (Redirect URI) الذي يُسجَّل في تطبيق {{ active.label }}:</span>
                <code dir="ltr" class="rounded bg-slate-100 px-2 py-1 text-slate-700">{{ active.redirect_uri }}</code>
                <button type="button" class="rounded p-1 hover:bg-slate-100" title="نسخ" @click="copy(active.redirect_uri)"><Copy class="size-3.5" /></button>
            </div>

            <!-- WebDAV -->
            <form v-if="active?.auth === 'webdav'" class="mt-5 grid gap-4 border-t border-slate-100 pt-5 md:grid-cols-3" @submit.prevent="saveDav">
                <Field label="عنوان WebDAV" id="dav-url" :error="dav.errors.base_url" hint="مثال: https://mycloud.example.com:8443/webdav" class="md:col-span-3">
                    <input id="dav-url" v-model="dav.base_url" dir="ltr" class="field-input text-start" placeholder="https://" />
                </Field>
                <Field label="اسم المستخدم" id="dav-user" :error="dav.errors.username"><input id="dav-user" v-model="dav.username" dir="ltr" class="field-input text-start" autocomplete="off" /></Field>
                <Field label="كلمة المرور" id="dav-pass" :error="dav.errors.password" :hint="connection.webdav?.username ? 'اتركها فارغة للإبقاء على الحالية.' : ''">
                    <input id="dav-pass" v-model="dav.password" type="password" dir="ltr" class="field-input text-start" autocomplete="new-password" />
                </Field>
                <div class="flex items-end"><Btn type="submit" :loading="dav.processing"><Link2 class="size-4" /> اختبار وحفظ</Btn></div>
            </form>

            <div v-if="failed" class="mt-4 flex flex-wrap items-center gap-3 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <AlertTriangle class="size-4" />
                <span class="me-auto"><span class="num font-semibold">{{ failed }}</span> ملف لم يكتمل رفعه. يُعاد تلقائياً كل 10 دقائق (حتى 5 محاولات).</span>
                <Btn size="sm" variant="secondary" @click="post('/settings/storage/retry')"><RotateCw class="size-4" /> إعادة المحاولة الآن</Btn>
            </div>
        </div>

        <form class="space-y-6" @submit.prevent="form.put('/settings/storage', { preserveScroll: true })">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <label
                    v-for="(p, key) in providers"
                    :key="key"
                    class="card relative cursor-pointer p-5 transition"
                    :class="form.provider === key ? 'border-brand ring-4 ring-brand/15' : 'hover:border-slate-300'"
                >
                    <input v-model="form.provider" type="radio" :value="key" class="sr-only" />
                    <CircleCheck v-if="form.provider === key" class="absolute top-4 end-4 size-5 text-brand" />
                    <p class="font-semibold text-slate-800">{{ p.label }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ p.auth === 'oauth' ? 'ربط بتسجيل دخول لمرة واحدة' : 'ربط بعنوان الجهاز واسم مستخدم وكلمة مرور' }}</p>
                    <p class="mt-3 text-xs text-slate-500">{{ p.note }}</p>
                    <p v-if="p.auth === 'oauth' && !p.configured" class="mt-2 text-xs text-amber-700">مفاتيح التطبيق غير مضافة بعد.</p>
                </label>
            </div>

            <div class="card grid gap-5 p-6 md:grid-cols-3">
                <Field label="الحد الأقصى لحجم الملف (ميغابايت)" id="max" :error="form.errors.max_upload_mb">
                    <input id="max" v-model.number="form.max_upload_mb" type="number" min="1" max="2048" dir="ltr" class="field-input text-start" />
                </Field>
                <Field label="الأنواع المسموحة" id="types" :error="form.errors.allowed_types" hint="مثال: pdf, jpg, png, mp4, ai, psd — اتركه فارغاً للسماح بكل الأنواع.">
                    <input id="types" v-model="typesText" dir="ltr" class="field-input text-start" />
                </Field>
                <Field label="اسم المجلد الرئيسي" id="root" :error="form.errors.root_folder_name" hint="يُنشأ في الخدمة عند أول رفع؛ لا يتغير بعدها.">
                    <input id="root" v-model="form.root_folder_name" class="field-input" />
                </Field>
            </div>

            <div class="flex items-start gap-2 rounded-xl bg-sky/10 px-4 py-3 text-sm text-navy">
                <Info class="mt-0.5 size-4 shrink-0" />
                <p v-if="dirtyProvider">احفظ اختيار المزود أولاً، ثم يظهر زر «ربط الحساب» في الأعلى. تغيير المزود لا ينقل الملفات المرفوعة سابقاً؛ تبقى في خدمتها وتُفتح منها.</p>
                <p v-else>الملفات تُنظَّم تلقائياً: المجلد الرئيسي ← العملاء ← مجلد لكل عميل (وفيه العقود، عروض الأسعار، والمشاريع بمجلد لكل مشروع).</p>
            </div>

            <Btn type="submit" :loading="form.processing">حفظ الإعدادات</Btn>
        </form>
    </AppLayout>
</template>

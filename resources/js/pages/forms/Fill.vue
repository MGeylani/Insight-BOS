<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { CheckCircle2 } from '@lucide/vue';
import Btn from '@/components/Btn.vue';

const props = defineProps({ form: Object, priorities: Object, signedIn: Boolean, sent: Object });
const page = usePage();
const answers = {};
const files = {};
props.form.fields.forEach((q) => {
    answers[q.key] = q.field?.type === 'labels' ? [] : q.field?.type === 'checkbox' ? false : '';
    if (q.key === 'files' || q.field?.type === 'file') files[q.key] = [];
});
const f = useForm({ answers, files, website: '' });
const errorFor = (k) => f.errors[`answers.${k}`];
const otherErrors = computed(() => Object.entries(f.errors).filter(([k]) => !k.startsWith('answers.')).map(([, v]) => v));
const submit = () => f.post(`/forms/${props.form.token}`, { forceFormData: true, preserveScroll: true, onSuccess: () => f.reset() });
const isFile = (q) => q.key === 'files' || q.field?.type === 'file';
const company = computed(() => page.props.company?.name ?? 'Insight Media');
</script>

<template>
    <Head :title="form.name" />
    <div class="min-h-screen bg-slate-50" dir="rtl">
        <header class="brand-gradient px-6 py-8 text-white">
            <div class="mx-auto flex max-w-2xl items-center gap-4">
                <img src="/images/logo-white.svg" :alt="company" class="h-10" />
                <h1 class="ms-auto text-2xl font-bold">{{ form.name }}</h1>
            </div>
        </header>
        <main class="mx-auto -mt-4 max-w-2xl px-4 pb-16">
            <div v-if="sent" class="card p-8 text-center">
                <CheckCircle2 class="mx-auto size-12 text-emerald-600" />
                <p class="mt-3 text-lg font-semibold text-navy">{{ form.confirmation || 'وصل طلبك، وسنتواصل معك قريباً.' }}</p>
                <p v-if="sent.code" class="num mt-1 text-sm text-slate-500" dir="ltr">{{ sent.code }}</p>
                <div class="mt-5 flex justify-center gap-2">
                    <Link v-if="sent.url" :href="sent.url" class="text-sm text-brand hover:underline">فتح المهمة</Link>
                    <Link :href="`/forms/${form.token}`" class="text-sm text-slate-600 hover:underline">إرسال طلب آخر</Link>
                </div>
            </div>
            <form v-else-if="form.is_active" class="card space-y-5 p-6" @submit.prevent="submit">
                <p v-if="form.description" class="whitespace-pre-line text-sm text-slate-600">{{ form.description }}</p>
                <div v-for="q in form.fields" :key="q.key">
                    <label :for="`q-${q.key}`" class="mb-1 block text-sm font-medium text-slate-800">{{ q.label }} <span v-if="q.required" class="text-red-600">*</span></label>
                    <p v-if="q.help" class="mb-1 text-xs text-slate-500">{{ q.help }}</p>
                    <textarea v-if="q.key === 'description'" :id="`q-${q.key}`" v-model="f.answers[q.key]" rows="5" class="field-input" />
                    <input v-else-if="q.key === 'due' || q.field?.type === 'date'" :id="`q-${q.key}`" v-model="f.answers[q.key]" type="date" class="field-input" />
                    <select v-else-if="q.key === 'priority'" :id="`q-${q.key}`" v-model="f.answers[q.key]" class="field-input"><option value="">—</option><option v-for="(l, k) in priorities" :key="k" :value="k">{{ l }}</option></select>
                    <input v-else-if="q.key === 'email'" :id="`q-${q.key}`" v-model="f.answers[q.key]" type="email" class="field-input" dir="ltr" />
                    <input v-else-if="isFile(q)" :id="`q-${q.key}`" type="file" multiple class="block w-full text-sm" @change="f.files[q.key] = [...$event.target.files]" />
                    <select v-else-if="q.field?.type === 'dropdown'" :id="`q-${q.key}`" v-model="f.answers[q.key]" class="field-input"><option value="">—</option><option v-for="c in q.field.options.choices" :key="c.id" :value="c.id">{{ c.label }}</option></select>
                    <div v-else-if="q.field?.type === 'labels'" class="flex flex-wrap gap-2"><label v-for="c in q.field.options.choices" :key="c.id" class="flex items-center gap-1.5 rounded-full border border-slate-200 px-3 py-1 text-sm">
                        <input v-model="f.answers[q.key]" type="checkbox" :value="c.id" class="rounded" /> {{ c.label }}</label></div>
                    <label v-else-if="q.field?.type === 'checkbox'" class="flex items-center gap-2 text-sm"><input :id="`q-${q.key}`" v-model="f.answers[q.key]" type="checkbox" class="rounded" /> نعم</label>
                    <input v-else-if="['number', 'money', 'percent', 'rating'].includes(q.field?.type)" :id="`q-${q.key}`" v-model="f.answers[q.key]" type="number" step="any" class="field-input" dir="ltr" />
                    <input v-else-if="q.field?.type === 'url'" :id="`q-${q.key}`" v-model="f.answers[q.key]" type="url" class="field-input" dir="ltr" placeholder="https://" />
                    <input v-else :id="`q-${q.key}`" v-model="f.answers[q.key]" class="field-input" />
                    <p v-if="errorFor(q.key)" class="field-error">{{ errorFor(q.key) }}</p>
                </div>
                <input v-model="f.website" type="text" name="website" tabindex="-1" autocomplete="off" class="absolute -start-[9999px] h-0 w-0 opacity-0" aria-hidden="true" />
                <p v-for="e in otherErrors" :key="e" class="field-error">{{ e }}</p>
                <div class="flex justify-end"><Btn type="submit" :loading="f.processing">إرسال الطلب</Btn></div>
            </form>
            <div v-else class="card p-8 text-center text-slate-600">هذا النموذج متوقف حالياً.</div>
            <p class="mt-6 text-center text-xs text-slate-400">{{ company }}</p>
        </main>
    </div>
</template>

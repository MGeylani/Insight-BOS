<script setup>
// Share a client / project / list: team members beyond their own scope, or guests (clients) who see only this.
import { computed, ref, watch } from 'vue';
import { Trash2, UserPlus, Copy, KeyRound } from '@lucide/vue';
import Modal from '@/components/Modal.vue';
import Btn from '@/components/Btn.vue';
import { json } from '@/lib/http';

const open = defineModel({ type: Boolean, default: false });
const props = defineProps({ location: Object });
const data = ref({ grants: [], levels: {}, users: [] });
const error = ref('');
const busy = ref(false);
const pick = ref({ user_id: '', level: 'view' });
const guest = ref({ name: '', email: '', level: 'view' });
const link = ref(null);
const copied = ref(false);
const q = computed(() => `type=${props.location?.type}&id=${props.location?.id}`);
const kind = computed(() => ({ client: 'العميل', project: 'المشروع', list: 'القائمة' })[props.location?.type]);

async function run(p) {
    error.value = '';
    busy.value = true;
    try { const r = await p; data.value = { ...data.value, ...r }; return r; } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
watch(open, (v) => { if (v) { link.value = null; run(json('GET', `/tasks/access?${q.value}`)); } });
const available = computed(() => data.value.users.filter((u) => !data.value.grants.some((g) => g.user?.id === u.id)));
const add = async () => { if (await run(json('POST', `/tasks/access?${q.value}`, pick.value))) pick.value = { user_id: '', level: 'view' }; };
const setLevel = (g, level) => run(json('PUT', `/tasks/access/${g.id}`, { level }));
const remove = (g) => confirm(`إلغاء مشاركة ${kind.value} مع ${g.user?.name}؟`) && run(json('DELETE', `/tasks/access/${g.id}`));
async function invite() {
    const r = await run(json('POST', `/tasks/access/invite?${q.value}`, guest.value));
    if (r) { link.value = { name: r.invited.name, url: r.link }; guest.value = { name: '', email: '', level: 'view' }; }
}
async function newLink(u) {
    const r = await run(json('POST', `/tasks/access/link/${u.id}`));
    if (r) link.value = { name: u.name, url: r.link };
}
const copy = async () => { await navigator.clipboard?.writeText(link.value.url); copied.value = true; setTimeout(() => (copied.value = false), 1500); };
</script>

<template>
    <Modal v-model="open" :title="`مشاركة ${kind}: ${location?.name ?? ''}`" wide>
        <div class="space-y-5 text-sm">
            <p class="text-slate-500">من تشاركه يرى كل مهام {{ kind }} (عرض وتعليق)، أو يعدّلها وينشئ مهاماً (تعديل)، أياً كانت صلاحياته العامة. <b>الضيف</b> (العميل أو متعاون خارجي) لا يرى إلا ما شوركه، ولا يرى المهام والتعليقات الداخلية ولا الوقت والتكاليف.</p>
            <p v-if="error" class="rounded-xl bg-red-50 p-3 text-red-800">{{ error }}</p>

            <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200">
                <li v-for="g in data.grants" :key="g.id" class="flex flex-wrap items-center gap-2 px-3 py-2">
                    <span class="min-w-0 flex-1 truncate"><span class="font-medium text-slate-800">{{ g.user?.name }}</span> <span class="text-xs text-slate-400" dir="ltr">{{ g.user?.email }}</span></span>
                    <span v-if="g.user?.is_guest" class="rounded-full bg-amber-50 px-2 py-0.5 text-xs text-amber-800">ضيف</span>
                    <span v-if="g.user && !g.user.is_active" class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500">موقوف</span>
                    <button v-if="g.user?.is_guest" type="button" class="rounded p-1 text-slate-400 hover:text-brand" title="رابط جديد لتعيين كلمة المرور" @click="newLink(g.user)"><KeyRound class="size-4" /></button>
                    <select :value="g.level" class="field-input w-40 py-1" @change="setLevel(g, $event.target.value)"><option v-for="(l, k) in data.levels" :key="k" :value="k">{{ l }}</option></select>
                    <button type="button" class="rounded p-1 text-slate-400 hover:text-red-600" title="إلغاء المشاركة" @click="remove(g)"><Trash2 class="size-4" /></button>
                </li>
                <li v-if="!data.grants.length" class="px-3 py-4 text-center text-slate-500">لم يُشارَك بعد.</li>
            </ul>

            <div v-if="link" class="rounded-xl bg-emerald-50 p-3">
                <p class="mb-1 text-emerald-800">أرسل إلى {{ link.name }} هذا الرابط ليعيّن كلمة مروره ثم يدخل (صالح لمدة محدودة):</p>
                <div class="flex gap-2"><input :value="link.url" readonly class="field-input py-1.5 text-xs" dir="ltr" @focus="$event.target.select()" /><Btn size="sm" variant="secondary" @click="copy"><Copy class="size-4" /> {{ copied ? 'نُسخ' : 'نسخ' }}</Btn></div>
            </div>

            <section class="grid gap-4 md:grid-cols-2">
                <div class="rounded-xl bg-slate-50 p-3">
                    <p class="mb-2 font-semibold text-navy">مشاركة مع شخص موجود</p>
                    <select v-model="pick.user_id" class="field-input mb-2 py-1.5"><option value="">اختر…</option>
                        <option v-for="u in available" :key="u.id" :value="u.id">{{ u.name }}{{ u.is_guest ? ' (ضيف)' : '' }}</option></select>
                    <div class="flex gap-2"><select v-model="pick.level" class="field-input py-1.5"><option v-for="(l, k) in data.levels" :key="k" :value="k">{{ l }}</option></select>
                        <Btn size="sm" :disabled="!pick.user_id" :loading="busy" @click="add">مشاركة</Btn></div>
                </div>
                <div class="rounded-xl bg-slate-50 p-3">
                    <p class="mb-2 flex items-center gap-1.5 font-semibold text-navy"><UserPlus class="size-4" /> دعوة ضيف</p>
                    <input v-model="guest.name" class="field-input mb-2 py-1.5" placeholder="الاسم" />
                    <input v-model="guest.email" type="email" class="field-input mb-2 py-1.5" placeholder="البريد الإلكتروني" dir="ltr" />
                    <div class="flex gap-2"><select v-model="guest.level" class="field-input py-1.5"><option v-for="(l, k) in data.levels" :key="k" :value="k">{{ l }}</option></select>
                        <Btn size="sm" :disabled="!guest.name || !guest.email" :loading="busy" @click="invite">دعوة</Btn></div>
                </div>
            </section>
        </div>
        <template #footer><Btn variant="secondary" @click="open = false">إغلاق</Btn></template>
    </Modal>
</template>

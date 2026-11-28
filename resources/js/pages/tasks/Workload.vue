<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ChevronRight, ChevronLeft, Palmtree, Trash2, Settings2 } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import Avatar from '@/components/Avatar.vue';
import Dropdown from '@/components/Dropdown.vue';
import { parseLocal, todayStr } from '@/lib/tasks';

const props = defineProps({
    mode: String, week: String, weeks: Number, project: Number, projects: Array, days: Array, people: Array, timeOff: Array, canManage: Boolean, me: Number,
});
const dayFmt = new Intl.DateTimeFormat('ar', { weekday: 'short', day: 'numeric' });
const dayNames = ['أحد', 'اثنين', 'ثلاثاء', 'أربعاء', 'خميس', 'جمعة', 'سبت'];
const go = (p) => router.get('/tasks/workload', { mode: props.mode, week: props.week, weeks: props.weeks, project: props.project || undefined, ...p }, { preserveScroll: true, preserveState: true });
const shift = (n) => { const d = parseLocal(props.week); d.setDate(d.getDate() + n * 7 * props.weeks); go({ week: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}` }); };
const tone = (c) => {
    if (c.off) return 'bg-slate-100 text-slate-400';
    if (!c.capacity) return c.hours ? 'bg-red-100 text-red-700' : 'bg-slate-50 text-slate-300';
    const r = c.hours / c.capacity;
    return r > 1 ? 'bg-red-100 text-red-700' : r >= 0.8 ? 'bg-amber-100 text-amber-800' : c.hours ? 'bg-emerald-50 text-emerald-700' : 'text-slate-300';
};
const fmt = (h) => (h ? (Math.round(h * 10) / 10).toString() : '');
const open = ref(null); // `${personId}:${day}` with the task list shown

// Timeline: bar placement of each task inside the visible days.
const bars = (p) => p.tasks.map((t) => {
    const s = (t.start ?? t.due).slice(0, 10);
    const e = t.due.slice(0, 10);
    const from = Math.max(0, props.days.findIndex((d) => d >= s));
    let to = props.days.findIndex((d) => d > e);
    to = to === -1 ? props.days.length : to;
    return { ...t, col: from + 1, span: Math.max(1, to - from), clipped: s < props.days[0] };
}).filter((b) => b.col <= props.days.length);

// Capacity and time off.
const capPerson = ref(null);
const capForm = useForm({ capacity_hours: 8, work_days: [] });
const editCap = (p) => { capPerson.value = p; capForm.capacity_hours = p.capacity_hours; capForm.work_days = [...p.work_days]; };
const saveCap = () => capForm.put(`/tasks/workload/capacity/${capPerson.value.id}`, { preserveScroll: true, onSuccess: () => (capPerson.value = null) });
const offOpen = ref(false);
const offForm = useForm({ user_id: props.me, starts_on: todayStr(), ends_on: todayStr(), type: 'vacation', note: '' });
const saveOff = () => offForm.post('/tasks/time-off', { preserveScroll: true, onSuccess: () => { offOpen.value = false; offForm.reset('note'); } });
const removeOff = (o) => confirm('حذف الإجازة؟') && router.delete(`/tasks/time-off/${o.id}`, { preserveScroll: true });
const offTypes = { vacation: 'إجازة', sick: 'مرضية', other: 'أخرى' };
const overCount = computed(() => props.people.filter((p) => p.over).length);
</script>

<template>
    <AppLayout title="ضغط العمل">
        <PageHeader :title="mode === 'timeline' ? 'الخط الزمني للفريق' : 'ضغط العمل'" :subtitle="`${days[0]} ← ${days[days.length - 1]}`">
            <template #actions>
                <select :value="project ?? ''" class="field-input w-auto" @change="go({ project: $event.target.value || undefined })">
                    <option value="">كل المشاريع</option><option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
                <select :value="weeks" class="field-input w-auto" @change="go({ weeks: Number($event.target.value) })"><option :value="1">أسبوع</option><option :value="2">أسبوعان</option></select>
                <Btn variant="secondary" @click="shift(-1)"><ChevronRight class="size-4" /></Btn>
                <Btn variant="secondary" @click="go({ week: undefined })">هذا الأسبوع</Btn>
                <Btn variant="secondary" @click="shift(1)"><ChevronLeft class="size-4" /></Btn>
                <Btn variant="secondary" @click="offOpen = true"><Palmtree class="size-4" /> إجازة</Btn>
            </template>
        </PageHeader>

        <nav class="mb-4 flex gap-1">
            <button v-for="m in [['workload', 'ضغط العمل'], ['timeline', 'الخط الزمني']]" :key="m[0]" type="button" class="rounded-xl px-4 py-2 text-sm" :class="mode === m[0] ? 'bg-navy font-semibold text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'" @click="go({ mode: m[0] })">{{ m[1] }}</button>
            <p v-if="overCount && mode === 'workload'" class="ms-auto self-center rounded-lg bg-red-50 px-3 py-1.5 text-sm text-red-700">{{ overCount }} تجاوز سعته في هذه الفترة</p>
        </nav>

        <div class="card overflow-x-auto">
            <table class="w-full min-w-[48rem] text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500">
                    <tr><th class="w-48 px-3 py-2 text-start font-medium">الموظف</th>
                        <th v-for="d in days" :key="d" class="px-1 py-2 text-center font-medium" :class="d === todayStr() ? 'text-brand' : ''">{{ dayFmt.format(parseLocal(d)) }}</th>
                        <th v-if="mode === 'workload'" class="w-24 px-2 py-2 text-center font-medium">المجموع</th></tr>
                </thead>
                <tbody>
                    <tr v-for="p in people" :key="p.id" class="border-t border-slate-100 align-top">
                        <td class="px-3 py-2">
                            <div class="flex items-center gap-2"><Avatar :name="p.name" size="size-7 text-[11px]" /><span class="truncate font-medium text-slate-800">{{ p.name }}</span>
                                <button v-if="canManage" type="button" class="ms-auto rounded p-1 text-slate-300 hover:text-brand" title="السعة وأيام العمل" @click="editCap(p)"><Settings2 class="size-3.5" /></button></div>
                            <p class="mt-0.5 text-[11px] text-slate-400">{{ p.capacity_hours }} س/يوم · {{ p.work_days.map((d) => dayNames[d]).join('، ') }}</p>
                        </td>
                        <template v-if="mode === 'workload'">
                            <td v-for="d in days" :key="d" class="px-1 py-2 text-center">
                                <Dropdown v-if="p.cells[d].tasks.length" width="w-72">
                                    <template #trigger>
                                        <button type="button" class="num w-full rounded-lg px-1 py-2 text-xs font-semibold" :class="tone(p.cells[d])" :title="p.cells[d].off ? offTypes[p.cells[d].off] : `${fmt(p.cells[d].hours)} من ${p.cells[d].capacity} ساعة`">
                                            {{ fmt(p.cells[d].hours) || '•' }}<span v-if="p.cells[d].capacity" class="font-normal opacity-60">/{{ p.cells[d].capacity }}</span>
                                        </button>
                                    </template>
                                    <Link v-for="t in p.tasks.filter((x) => p.cells[d].tasks.includes(x.id))" :key="t.id" :href="`/tasks/${t.id}`" class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-xs hover:bg-slate-50">
                                        <span class="num text-slate-400" dir="ltr">{{ t.code }}</span><span class="truncate" :class="t.overdue ? 'text-red-600' : ''">{{ t.title }}</span><span class="num ms-auto text-slate-500">{{ t.hours || '—' }}س</span>
                                    </Link>
                                </Dropdown>
                                <span v-else class="num block rounded-lg px-1 py-2 text-xs" :class="tone(p.cells[d])">{{ p.cells[d].off ? '🌴' : '' }}</span>
                            </td>
                            <td class="num px-2 py-2 text-center text-xs font-semibold" :class="p.over ? 'text-red-600' : 'text-slate-700'">{{ fmt(p.total) || 0 }}/{{ p.capacity }}</td>
                        </template>
                        <td v-else :colspan="days.length" class="px-1 py-2">
                            <div class="grid gap-1" :style="{ gridTemplateColumns: `repeat(${days.length}, minmax(0, 1fr))` }">
                                <div v-for="d in days" :key="'o' + d" class="h-0" />
                                <Link v-for="b in bars(p)" :key="b.id" :href="`/tasks/${b.id}`" class="truncate rounded-md px-2 py-1 text-[11px] font-medium text-white hover:opacity-90"
                                    :style="{ gridColumn: `${b.col} / span ${b.span}`, background: b.overdue ? '#ef4444' : (b.status?.color ?? '#0082F6') }" :title="`${b.code} ${b.title}`">
                                    {{ b.clipped ? '… ' : '' }}{{ b.title }}
                                </Link>
                                <p v-if="!p.tasks.length" class="text-xs text-slate-400" :style="{ gridColumn: `1 / span ${days.length}` }">لا مهام بتواريخ في هذه الفترة.</p>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-slate-500">التقدير يُقسم على المسؤولين ثم على أيام العمل بين البدء والتسليم. المهام بلا تقدير لا تضيف ساعات. 🌴 = إجازة.</p>

        <div v-if="timeOff.length" class="card mt-6 p-5">
            <h3 class="mb-3 font-semibold text-navy">الإجازات في هذه الفترة</h3>
            <ul class="divide-y divide-slate-100 text-sm">
                <li v-for="o in timeOff" :key="o.id" class="flex items-center gap-3 py-2">
                    <span class="font-medium">{{ o.user }}</span><span class="text-slate-500">{{ offTypes[o.type] }}</span>
                    <span class="num text-xs text-slate-500" dir="ltr">{{ o.starts_on }} → {{ o.ends_on }}</span><span class="text-xs text-slate-500">{{ o.note }}</span>
                    <button v-if="canManage || o.user_id === me" type="button" class="ms-auto rounded p-1 text-slate-400 hover:text-red-600" @click="removeOff(o)"><Trash2 class="size-4" /></button>
                </li>
            </ul>
        </div>

        <Modal :model-value="!!capPerson" :title="`السعة: ${capPerson?.name ?? ''}`" @update:model-value="capPerson = null">
            <div class="space-y-4">
                <Field label="ساعات العمل يومياً" id="cap-h" :error="capForm.errors.capacity_hours"><input id="cap-h" v-model="capForm.capacity_hours" type="number" step="0.5" min="0" max="24" class="field-input" /></Field>
                <div><p class="mb-1 text-sm font-medium text-slate-700">أيام العمل</p>
                    <div class="flex flex-wrap gap-1"><label v-for="(n, i) in dayNames" :key="i" class="flex items-center gap-1 rounded-lg border border-slate-200 px-2 py-1 text-sm"><input v-model="capForm.work_days" type="checkbox" :value="i" class="rounded" /> {{ n }}</label></div></div>
            </div>
            <template #footer><Btn variant="secondary" @click="capPerson = null">إلغاء</Btn><Btn :loading="capForm.processing" @click="saveCap">حفظ</Btn></template>
        </Modal>

        <Modal v-model="offOpen" title="تسجيل إجازة">
            <div class="grid gap-4 sm:grid-cols-2">
                <Field v-if="canManage" label="الموظف" id="off-u" class="sm:col-span-2"><select id="off-u" v-model="offForm.user_id" class="field-input"><option v-for="p in people" :key="p.id" :value="p.id">{{ p.name }}</option></select></Field>
                <Field label="من" id="off-s" :error="offForm.errors.starts_on"><input id="off-s" v-model="offForm.starts_on" type="date" dir="ltr" class="field-input" /></Field>
                <Field label="إلى" id="off-e" :error="offForm.errors.ends_on"><input id="off-e" v-model="offForm.ends_on" type="date" dir="ltr" class="field-input" /></Field>
                <Field label="النوع" id="off-t"><select id="off-t" v-model="offForm.type" class="field-input"><option v-for="(l, k) in offTypes" :key="k" :value="k">{{ l }}</option></select></Field>
                <Field label="ملاحظة" id="off-n"><input id="off-n" v-model="offForm.note" class="field-input" /></Field>
            </div>
            <template #footer><Btn variant="secondary" @click="offOpen = false">إلغاء</Btn><Btn :loading="offForm.processing" @click="saveOff">حفظ</Btn></template>
        </Modal>
    </AppLayout>
</template>

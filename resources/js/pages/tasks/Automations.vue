<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Plus, Zap, Pencil, Trash2, Sparkles, CheckCircle2, XCircle } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Toggle from '@/components/Toggle.vue';
import AutomationModal from '@/components/tasks/AutomationModal.vue';
import { formatDateTime } from '@/lib/format';

const props = defineProps({ location: Object, automations: Array, triggers: Object, actions: Object, recipes: Array, statuses: Array, fields: Array, templates: Array, lists: Array, users: Array, tags: Array, priorities: Object, types: Array, groups: Object });
const open = ref(false);
const editing = ref(null);
const preset = ref(null);
const edit = (a) => { editing.value = a; preset.value = null; open.value = true; };
const fromRecipe = (r) => { editing.value = null; preset.value = r; open.value = true; };
const toggle = (a, v) => router.put(`/tasks/automations/${a.id}`, { is_active: v }, { preserveScroll: true });
const remove = (a) => confirm(`حذف الأتمتة «${a.name}»؟`) && router.delete(`/tasks/automations/${a.id}`, { preserveScroll: true });
const back = props.location ? `/tasks?${props.location.type}=${props.location.id}` : '/tasks';
</script>

<template>
    <AppLayout title="الأتمتة">
        <PageHeader title="الأتمتة" :subtitle="location ? `${location.name}: تعمل على مهام هذا المكان فقط` : 'تعمل على كل المهام'">
            <template #actions><Link :href="back" class="text-sm text-brand hover:underline">العودة للمهام</Link><Btn @click="edit(null)"><Plus class="size-4" /> أتمتة</Btn></template>
        </PageHeader>
        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-3 xl:col-span-2">
                <div v-for="a in automations" :key="a.id" class="card p-4">
                    <div class="flex items-center gap-3">
                        <Zap class="size-5" :class="a.is_active ? 'text-amber-500' : 'text-slate-300'" />
                        <div class="min-w-0 flex-1"><p class="font-semibold text-slate-800">{{ a.name }}</p>
                            <p class="text-xs text-slate-500">{{ triggers[a.trigger] }} ← {{ a.actions.map((x) => actions[x.type]).join('، ') }} · نُفّذت {{ a.runs_count }} مرة<template v-if="a.last_run_at"> · آخرها {{ formatDateTime(a.last_run_at) }}</template></p></div>
                        <Toggle :model-value="a.is_active" @update:model-value="(v) => toggle(a, v)" />
                        <button type="button" class="rounded p-1 text-slate-400 hover:text-brand" @click="edit(a)"><Pencil class="size-4" /></button>
                        <button type="button" class="rounded p-1 text-slate-400 hover:text-red-600" @click="remove(a)"><Trash2 class="size-4" /></button>
                    </div>
                    <ul v-if="a.runs.length" class="mt-2 space-y-0.5 border-t border-slate-100 pt-2 text-xs">
                        <li v-for="r in a.runs" :key="r.id" class="flex items-center gap-2">
                            <CheckCircle2 v-if="r.status === 'ok'" class="size-3.5 text-emerald-600" /><XCircle v-else class="size-3.5 text-red-600" />
                            <span class="text-slate-400">{{ formatDateTime(r.created_at) }}</span>
                            <Link v-if="r.task_id" :href="`/tasks/${r.task_id}`" class="text-brand hover:underline">#{{ r.task_id }}</Link>
                            <span class="truncate" :class="r.status === 'ok' ? 'text-slate-600' : 'text-red-700'">{{ r.message }}</span>
                        </li>
                    </ul>
                </div>
                <p v-if="!automations.length" class="card p-8 text-center text-sm text-slate-500">لا أتمتة هنا بعد. ابدأ من الوصفات الجاهزة.</p>
            </div>
            <div class="card p-5">
                <h3 class="mb-3 flex items-center gap-2 font-semibold text-navy"><Sparkles class="size-4 text-amber-500" /> وصفات جاهزة للوكالة</h3>
                <ul class="space-y-2 text-sm">
                    <li v-for="r in recipes" :key="r.key" class="rounded-xl border border-slate-200 p-3">
                        <p class="font-medium text-slate-800">{{ r.name }}</p>
                        <p v-if="r.needs" class="mt-0.5 text-xs text-amber-700">{{ r.needs }}</p>
                        <Btn class="mt-2" size="sm" variant="secondary" @click="fromRecipe(r)"><Plus class="size-4" /> استخدمها</Btn>
                    </li>
                </ul>
                <p class="mt-3 text-xs text-slate-500">لاحقاً مع بوابة العميل والمالية: «اعتماد العميل لمخرج ← مسودة فاتورة» و«إرسال رابط المراجعة للعميل».</p>
            </div>
        </div>
        <AutomationModal v-model="open" :automation="editing" :preset="preset" :location="location" :triggers="triggers" :action-types="actions" :statuses="statuses"
            :fields="fields" :templates="templates" :lists="lists" :users="users" :tags="tags" :priorities="priorities" :types="types" />
    </AppLayout>
</template>

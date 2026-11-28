<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Pencil, Trash2, Building2, FileText, FileSignature, UserRound, CalendarDays, Users, LayoutDashboard, Flag,
    ShieldAlert, GitPullRequestArrow, FolderOpen, AlertTriangle, ListChecks,
} from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Badge from '@/components/Badge.vue';
import MilestonesPanel from '@/components/projects/MilestonesPanel.vue';
import RisksIssuesPanel from '@/components/projects/RisksIssuesPanel.vue';
import ChangesPanel from '@/components/projects/ChangesPanel.vue';
import AttachmentsPanel from '@/components/AttachmentsPanel.vue';
import InvoiceList from '@/components/finance/InvoiceList.vue';
import { formatDate, formatDateTime } from '@/lib/format';
import { money, usd } from '@/lib/crm';
import { projectStatusTone, daysLabel, riskLevelTone } from '@/lib/projects';
import { minutesLabel } from '@/lib/tasks';

const props = defineProps({
    project: Object, milestones: Array, taskLists: { type: Array, default: null }, risks: Array, issues: Array, changes: Array, log: Array, can: Object, files: Object,
    types: Object, statuses: Object, memberRoles: Array, riskLevels: Object, riskCategories: Object, riskStatuses: Object,
    issueSeverities: Object, issueStatuses: Object, changeSources: Object, changeStatuses: Object, users: Array,
    invoices: { type: Array, default: () => [] }, invoiceStatuses: { type: Object, default: () => ({}) },
});
const page = usePage();
const p = computed(() => props.project);
const errors = computed(() => Object.values(page.props.errors ?? {}));

const tabs = computed(() => [
    { key: 'summary', label: 'الملخص', icon: LayoutDashboard },
    { key: 'milestones', label: 'المراحل', icon: Flag, count: props.milestones.length },
    { key: 'risks', label: 'المخاطر والمشكلات', icon: ShieldAlert, count: openIssues.value.length || null },
    { key: 'changes', label: 'طلبات التغيير', icon: GitPullRequestArrow, count: pendingChanges.value.length || null },
    { key: 'files', label: 'الملفات', icon: FolderOpen, count: props.files.items.length || null },
]);
const initial = typeof window !== 'undefined' ? window.location.hash.slice(1) : '';
const tab = ref(['summary', 'milestones', 'risks', 'changes', 'files'].includes(initial) ? initial : 'summary');
watch(tab, (t) => history.replaceState(history.state, '', `${window.location.pathname}${window.location.search}${t === 'summary' ? '' : '#' + t}`));

const openIssues = computed(() => props.issues.filter((i) => i.status !== 'resolved'));
const openRisks = computed(() => props.risks.filter((r) => r.status === 'open'));
const pendingChanges = computed(() => props.changes.filter((c) => c.status === 'pending'));
const nextMilestone = computed(() => props.milestones.find((m) => m.status !== 'done'));
const daysLeft = computed(() => {
    if (!p.value.due_date) return null;
    return Math.round((new Date(p.value.due_date) - new Date(new Date().toISOString().slice(0, 10))) / 86400000);
});

const setStatus = (status) => router.post(`/projects/${p.value.id}/status`, { status }, { preserveScroll: true, preserveState: true });
const destroy = () => confirm(`أرشفة المشروع ${p.value.name}؟`) && router.delete(`/projects/${p.value.id}`);
</script>

<template>
    <AppLayout :title="p.name">
        <PageHeader :title="p.name" :subtitle="`${p.number} · ${types[p.type]} · ${p.client?.name}`">
            <template #actions>
                <select v-if="can.edit" :value="p.status" class="field-input w-auto" title="حالة المشروع" @change="setStatus($event.target.value)">
                    <option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option>
                </select>
                <Btn v-if="can.edit" variant="secondary" :href="`/projects/${p.id}/edit`"><Pencil class="size-4" /> تعديل</Btn>
                <Btn v-if="can.delete" variant="ghost" title="أرشفة المشروع" @click="destroy"><Trash2 class="size-4" /></Btn>
            </template>
        </PageHeader>

        <div v-if="errors.length" class="mb-6 rounded-2xl bg-red-50 p-4 text-sm text-red-800"><p v-for="e in errors" :key="e">{{ e }}</p></div>
        <div v-if="p.overdue" class="mb-6 flex items-center gap-2 rounded-2xl bg-red-50 p-4 text-sm text-red-900">
            <AlertTriangle class="size-4 shrink-0" /> تجاوز المشروع موعد التسليم ({{ formatDate(p.due_date) }}). حدّث الموعد بطلب تغيير، أو أنهِه إن اكتمل.
        </div>

        <nav class="mb-6 flex gap-1 overflow-x-auto rounded-2xl border border-slate-200/80 bg-white p-1">
            <button v-for="t in tabs" :key="t.key" type="button" class="flex items-center gap-2 whitespace-nowrap rounded-xl px-4 py-2 text-sm transition"
                :class="tab === t.key ? 'bg-navy font-semibold text-white' : 'text-slate-600 hover:bg-slate-100'" @click="tab = t.key">
                <component :is="t.icon" class="size-4" /> {{ t.label }}
                <span v-if="t.count" class="num rounded-full px-1.5 text-xs" :class="tab === t.key ? 'bg-white/20' : 'bg-slate-100'">{{ t.count }}</span>
            </button>
        </nav>

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <template v-if="tab === 'summary'">
                    <div class="card overflow-hidden">
                        <div class="brand-gradient h-1.5"></div>
                        <div class="p-6">
                            <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <p class="text-xs text-slate-500">العميل</p>
                                    <Link :href="`/crm/clients/${p.client?.id}`" class="text-lg font-bold text-navy hover:underline">{{ p.client?.name }}</Link>
                                </div>
                                <div class="flex gap-1">
                                    <Badge :tone="projectStatusTone[p.status]">{{ statuses[p.status] }}</Badge>
                                    <Badge v-if="p.overdue" tone="red">متأخر</Badge>
                                </div>
                            </div>
                            <dl class="grid gap-4 rounded-2xl bg-slate-50 p-4 text-sm sm:grid-cols-4">
                                <div><dt class="text-xs text-slate-500">البدء</dt><dd class="mt-0.5">{{ formatDate(p.start_date) }}</dd></div>
                                <div><dt class="text-xs text-slate-500">التسليم</dt><dd class="mt-0.5" :class="{ 'font-semibold text-red-600': p.overdue }">{{ formatDate(p.due_date) }}</dd></div>
                                <div><dt class="text-xs text-slate-500">المتبقي</dt><dd class="mt-0.5"><template v-if="daysLeft !== null && !['completed', 'cancelled'].includes(p.status)"><span class="num">{{ Math.abs(daysLeft) }}</span> يوماً {{ daysLeft < 0 ? 'تأخير' : '' }}</template><template v-else>—</template></dd></div>
                                <div><dt class="text-xs text-slate-500">التقدم</dt><dd class="num mt-0.5 font-bold text-navy">{{ p.progress === null ? '—' : p.progress + '%' }}</dd></div>
                                <template v-if="can.see_budget">
                                    <div><dt class="text-xs text-slate-500">الميزانية</dt><dd class="num mt-0.5 font-bold text-navy">{{ money(p.budget, p.currency) }}</dd><dd v-if="p.currency !== 'USD' && p.budget_usd !== null" class="num text-xs text-slate-500">{{ usd(p.budget_usd) }}</dd></div>
                                    <div><dt class="text-xs text-slate-500">منها تغييرات معتمدة</dt><dd class="num mt-0.5">{{ p.approved_cost ? money(p.approved_cost, p.currency) : '—' }}</dd></div>
                                </template>
                                <div><dt class="text-xs text-slate-500">تمديد معتمد</dt><dd class="mt-0.5">{{ daysLabel(p.approved_days) }}</dd></div>
                                <div v-if="p.time"><dt class="text-xs text-slate-500">الساعات المسجلة</dt><dd class="num mt-0.5 font-bold text-navy">{{ minutesLabel(p.time.minutes) || '—' }}</dd>
                                    <dd v-if="p.time.minutes" class="num text-xs text-slate-500">قابلة للفوترة {{ minutesLabel(p.time.billable_minutes) || '0د' }}</dd></div>
                                <div v-if="p.time && can.see_budget"><dt class="text-xs text-slate-500">تكلفة الساعات</dt><dd class="num mt-0.5 font-bold text-plum">{{ usd(p.time.cost) }}</dd>
                                    <dd v-if="p.time.uncosted_minutes" class="text-[11px] text-amber-700">{{ minutesLabel(p.time.uncosted_minutes) }} بلا تكلفة ساعة</dd>
                                    <dd v-else-if="p.budget_usd" class="num text-xs text-slate-500">{{ Math.round((p.time.cost / p.budget_usd) * 100) }}% من الميزانية</dd></div>
                            </dl>
                            <div v-if="p.progress !== null" class="mt-5 h-2 overflow-hidden rounded-full bg-slate-100"><div class="brand-gradient h-full rounded-full" :style="{ width: p.progress + '%' }" /></div>
                            <div v-if="p.description" class="mt-5 text-sm"><p class="mb-1 font-semibold text-navy">الوصف ونطاق العمل</p><p class="whitespace-pre-line leading-relaxed text-slate-700">{{ p.description }}</p></div>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <button type="button" class="card p-5 text-start transition hover:ring-2 hover:ring-brand/20" @click="tab = 'milestones'">
                            <p class="text-xs text-slate-500">المرحلة القادمة</p>
                            <p class="mt-1 font-semibold text-slate-800">{{ nextMilestone?.title ?? 'لا يوجد' }}</p>
                            <p v-if="nextMilestone" class="text-xs" :class="nextMilestone.status === 'overdue' ? 'font-semibold text-amber-700' : 'text-slate-500'">{{ formatDate(nextMilestone.due_date) }}</p>
                        </button>
                        <button type="button" class="card p-5 text-start transition hover:ring-2 hover:ring-brand/20" @click="tab = 'risks'">
                            <p class="text-xs text-slate-500">مخاطر قائمة · مشكلات مفتوحة</p>
                            <p class="num mt-1 text-2xl font-bold"><span :class="openRisks.some((r) => r.level === 'high') ? 'text-red-600' : 'text-navy'">{{ openRisks.length }}</span> <span class="text-slate-300">·</span> <span :class="openIssues.length ? 'text-amber-700' : 'text-navy'">{{ openIssues.length }}</span></p>
                        </button>
                        <button type="button" class="card p-5 text-start transition hover:ring-2 hover:ring-brand/20" @click="tab = 'changes'">
                            <p class="text-xs text-slate-500">طلبات تغيير بانتظار الاعتماد</p>
                            <p class="num mt-1 text-2xl font-bold" :class="pendingChanges.length ? 'text-amber-700' : 'text-navy'">{{ pendingChanges.length }}</p>
                        </button>
                    </div>

                    <div v-if="openRisks.length" class="card p-5">
                        <h3 class="mb-3 font-semibold text-navy">أعلى المخاطر</h3>
                        <ul class="space-y-2 text-sm">
                            <li v-for="r in openRisks.slice(0, 3)" :key="r.id" class="flex items-center gap-2">
                                <Badge :tone="riskLevelTone[r.level]"><span class="num">{{ r.score }}</span></Badge>
                                <span class="text-slate-700">{{ r.title }}</span>
                            </li>
                        </ul>
                    </div>
                </template>

                <MilestonesPanel v-else-if="tab === 'milestones'" :project-id="p.id" :milestones="milestones" :can-edit="can.edit" />

                <RisksIssuesPanel v-else-if="tab === 'risks'" :project-id="p.id" :manager-id="p.manager_id" :risks="risks" :issues="issues" :can-edit="can.edit" :users="users"
                    :risk-levels="riskLevels" :risk-categories="riskCategories" :risk-statuses="riskStatuses" :issue-severities="issueSeverities" :issue-statuses="issueStatuses" />

                <ChangesPanel v-else-if="tab === 'changes'" :project-id="p.id" :currency="p.currency" :changes="changes" :can-edit="can.edit" :can-approve="can.approve"
                    :see-budget="can.see_budget" :change-sources="changeSources" :change-statuses="changeStatuses" />

                <AttachmentsPanel v-else-if="tab === 'files'" :files="files" title="ملفات المشروع" hint="تُحفظ في مجلد المشروع داخل مجلد العميل" />
            </div>

            <div class="space-y-6">
                <div class="card p-5">
                    <dl class="space-y-3 text-sm">
                        <div class="flex items-center gap-2"><Building2 class="size-4 text-slate-400" /><Link :href="`/crm/clients/${p.client?.id}`" class="text-brand hover:underline">{{ p.client?.name }}</Link></div>
                        <div v-if="p.quote" class="flex items-center gap-2"><FileText class="size-4 text-slate-400" /><Link :href="`/crm/quotes/${p.quote.id}`" class="text-brand hover:underline">عرض {{ p.quote.number }}</Link></div>
                        <div v-if="p.contract" class="flex items-center gap-2"><FileSignature class="size-4 text-slate-400" /><Link :href="`/crm/contracts/${p.contract.id}`" class="text-brand hover:underline">عقد {{ p.contract.number ?? 'مسودة' }}</Link></div>
                        <div class="flex items-center gap-2"><UserRound class="size-4 text-slate-400" />المدير: {{ p.manager?.name ?? '—' }}</div>
                        <div class="flex items-center gap-2"><CalendarDays class="size-4 text-slate-400" />أُنشئ {{ formatDate(p.created_at) }}<template v-if="p.creator"> · {{ p.creator.name }}</template></div>
                        <div v-if="p.completed_at" class="text-xs text-emerald-700">اكتمل {{ formatDate(p.completed_at) }}</div>
                    </dl>
                </div>
                <InvoiceList v-if="invoiceStatuses.draft" :invoices="invoices" :statuses="invoiceStatuses"
                    :create-href="can.create_invoice ? `/finance/invoices/create?project_id=${p.id}` : null" />
                <div v-if="taskLists" class="card p-5">
                    <div class="mb-3 flex items-center gap-2"><ListChecks class="size-4 text-slate-400" /><h3 class="me-auto font-semibold text-navy">قوائم المهام</h3>
                        <Link :href="`/tasks?project=${p.id}`" class="text-xs text-brand hover:underline">كل المهام</Link>
                    </div>
                    <ul v-if="taskLists.length" class="space-y-2 text-sm">
                        <li v-for="l in taskLists" :key="l.id" class="flex items-center justify-between gap-2">
                            <Link :href="`/tasks?list=${l.id}`" class="truncate text-slate-700 hover:text-brand hover:underline">{{ l.name }}</Link>
                            <span class="num shrink-0 text-xs text-slate-500">{{ l.done }}/{{ l.total }}</span>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-slate-500">لا توجد قوائم بعد. <Link :href="`/tasks?project=${p.id}`" class="text-brand hover:underline">أنشئ قائمة</Link></p>
                </div>
                <div class="card p-5">
                    <div class="mb-3 flex items-center gap-2"><Users class="size-4 text-slate-400" /><h3 class="me-auto font-semibold text-navy">الفريق</h3>
                        <Link v-if="can.edit" :href="`/projects/${p.id}/edit`" class="text-xs text-brand hover:underline">تعديل</Link>
                    </div>
                    <ul v-if="p.members.length" class="space-y-2 text-sm">
                        <li v-for="m in p.members" :key="m.id" class="flex items-center justify-between gap-2"><span class="text-slate-700">{{ m.name }}</span><span class="text-xs text-slate-500">{{ m.role }}</span></li>
                    </ul>
                    <p v-else class="text-sm text-slate-500">لم يُضف أعضاء بعد.</p>
                </div>
                <div class="card p-5">
                    <h3 class="mb-3 font-semibold text-navy">السجل</h3>
                    <ol class="relative space-y-4 border-s border-slate-200 ps-4">
                        <li v-for="l in log" :key="l.id" class="text-sm">
                            <span class="absolute -start-1.5 mt-1.5 size-3 rounded-full border-2 border-white bg-brand" />
                            <p class="text-slate-700">{{ l.description }}</p>
                            <p class="text-xs text-slate-500">{{ l.user ?? '—' }} · {{ formatDateTime(l.created_at) }}</p>
                        </li>
                    </ol>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

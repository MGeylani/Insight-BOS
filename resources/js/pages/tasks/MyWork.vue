<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { AlertTriangle, Sun, CalendarRange, CalendarClock, CircleDashed, MessageSquareWarning, Bell, Check, Trash2, Timer, CalendarCheck } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import MyWorkTabs from '@/components/tasks/MyWorkTabs.vue';
import TaskRow from '@/components/tasks/TaskRow.vue';
import ReminderButton from '@/components/tasks/ReminderButton.vue';
import { formatDateTime } from '@/lib/format';
import { dueLabel, minutesLabel, keep } from '@/lib/tasks';

const props = defineProps({
    overdue: Array, today: Array, upcoming: Array, later: Array, noDate: Array, listStatuses: [Object, Array], comments: Array, reminders: Array, week: Object, appointments: Array,
    statuses: Array, priorities: Object, groups: Object, users: Array, tags: Array, can: Object,
});
const sections = computed(() => [
    { key: 'overdue', label: 'متأخرة', icon: AlertTriangle, tone: 'text-red-600', rows: props.overdue },
    { key: 'today', label: 'اليوم', icon: Sun, tone: 'text-amber-600', rows: props.today },
    { key: 'upcoming', label: 'الأيام السبعة القادمة', icon: CalendarRange, tone: 'text-brand', rows: props.upcoming },
    { key: 'later', label: 'لاحقاً', icon: CalendarClock, tone: 'text-plum', rows: props.later },
    { key: 'noDate', label: 'بلا تاريخ', icon: CircleDashed, tone: 'text-slate-400', rows: props.noDate },
]);
// Rows come from many lists: each row offers its own list's statuses.
const rowStatuses = (t) => props.listStatuses?.[t.list_id] ?? [t.status].filter(Boolean);
const doneReminder = (r) => router.patch(`/reminders/${r.id}`, { done: true }, keep);
const dropReminder = (r) => router.delete(`/reminders/${r.id}`, keep);
const total = computed(() => props.overdue.length + props.today.length);
</script>

<template>
    <AppLayout title="عملي">
        <PageHeader title="عملي" :subtitle="total ? `${total} مهمة تحتاجك اليوم` : 'لا شيء متأخر أو مستحق اليوم'" />
        <MyWorkTabs />
        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-5 xl:col-span-2">
                <template v-for="s in sections" :key="s.key">
                    <div v-if="s.rows.length || s.key === 'today'" class="card overflow-hidden">
                        <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-2.5">
                            <component :is="s.icon" class="size-4" :class="s.tone" /><h2 class="font-semibold text-navy">{{ s.label }}</h2>
                            <span class="num text-xs text-slate-400">{{ s.rows.length }}</span>
                        </div>
                        <TaskRow v-for="t in s.rows" :key="t.id" :task="{ ...t, children: [], children_count: 0 }" :statuses="rowStatuses(t)" :users="users" :tags="tags"
                            :priorities="priorities" :can="{ ...can, create: false }" show-location />
                        <p v-if="!s.rows.length" class="px-4 py-4 text-sm text-slate-500">لا مهام مستحقة اليوم. 🎉</p>
                    </div>
                </template>
            </div>

            <div class="space-y-5">
                <div v-if="can.track" class="card flex items-center gap-3 p-4">
                    <Timer class="size-5 text-emerald-600" />
                    <div class="flex-1"><p class="text-xs text-slate-500">ساعاتي هذا الأسبوع</p><p class="num text-lg font-bold text-navy">{{ minutesLabel(week.minutes) }}</p></div>
                    <div class="text-end text-xs text-slate-500">قابلة للفوترة<br /><span class="num font-semibold text-slate-700">{{ minutesLabel(week.billable_minutes) }}</span></div>
                    <Link href="/my-work/time" class="text-xs text-brand hover:underline">التفاصيل</Link>
                </div>

                <div class="card overflow-hidden">
                    <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-2.5">
                        <MessageSquareWarning class="size-4 text-amber-600" /><h2 class="font-semibold text-navy">تعليقات مسندة إليّ</h2>
                        <span class="num text-xs text-slate-400">{{ comments.length }}</span>
                    </div>
                    <ul class="divide-y divide-slate-100">
                        <li v-for="c in comments" :key="c.id">
                            <Link :href="`/tasks/${c.task.id}#comment-${c.id}`" class="block px-4 py-3 text-sm hover:bg-slate-50">
                                <p class="text-xs text-slate-500"><span class="num" dir="ltr">{{ c.task.code }}</span> · {{ c.task.title }}</p>
                                <p class="mt-0.5 text-slate-700">{{ c.excerpt }}</p>
                                <p class="mt-0.5 text-xs text-slate-400">{{ c.user }} · {{ formatDateTime(c.created_at) }}</p>
                            </Link>
                        </li>
                    </ul>
                    <p v-if="!comments.length" class="px-4 py-4 text-sm text-slate-500">لا تعليقات بانتظارك.</p>
                </div>

                <div v-if="appointments.length" class="card overflow-hidden">
                    <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-2.5"><CalendarCheck class="size-4 text-plum" /><h2 class="font-semibold text-navy">مواعيد اليوم</h2></div>
                    <ul class="divide-y divide-slate-100 text-sm">
                        <li v-for="a in appointments" :key="a.id" class="flex items-center gap-2 px-4 py-2.5"><span class="num w-12 text-xs text-slate-500" dir="ltr">{{ a.due_at.slice(11) }}</span>{{ a.title }}</li>
                    </ul>
                    <Link href="/crm/activities" class="block border-t border-slate-100 px-4 py-2 text-xs text-brand hover:bg-slate-50">كل الأنشطة</Link>
                </div>

                <div class="card overflow-hidden">
                    <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-2.5">
                        <Bell class="size-4 text-brand" /><h2 class="me-auto font-semibold text-navy">تذكيراتي</h2>
                        <ReminderButton label="تذكير" />
                    </div>
                    <ul class="divide-y divide-slate-100 text-sm">
                        <li v-for="r in reminders" :key="r.id" class="flex items-start gap-2 px-4 py-2.5">
                            <div class="min-w-0 flex-1">
                                <p :class="r.sent ? 'font-semibold text-amber-700' : 'text-slate-700'">{{ r.note || r.task?.title || 'تذكير' }}</p>
                                <p class="text-xs text-slate-500">{{ dueLabel(r.remind_at) }}<template v-if="r.task"> · <Link :href="`/tasks/${r.task.id}`" class="hover:underline">{{ r.task.code }}</Link></template></p>
                            </div>
                            <button type="button" class="rounded p-1 text-slate-400 hover:text-emerald-600" title="تم" @click="doneReminder(r)"><Check class="size-4" /></button>
                            <button type="button" class="rounded p-1 text-slate-400 hover:text-red-600" title="حذف" @click="dropReminder(r)"><Trash2 class="size-4" /></button>
                        </li>
                    </ul>
                    <p v-if="!reminders.length" class="px-4 py-4 text-sm text-slate-500">لا تذكيرات.</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

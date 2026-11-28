<script setup>
import { Link, router } from '@inertiajs/vue3';
import { Check, X, RotateCcw } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Avatar from '@/components/Avatar.vue';
import { minutesLabel } from '@/lib/tasks';
import { formatDateTime } from '@/lib/format';

const props = defineProps({ status: String, statuses: Object, sheets: Array });
const keep = { preserveScroll: true };
const decide = (s, decision) => {
    const note = decision === 'approve' ? null : prompt(decision === 'reject' ? 'سبب الرفض (يصل للموظف):' : 'ملاحظة (اختياري):');
    if (decision === 'reject' && note === null) return;
    router.post(`/timesheets/${s.id}/decide`, { decision, note }, keep);
};
</script>

<template>
    <AppLayout title="جداول الساعات">
        <PageHeader title="جداول الساعات" subtitle="اعتماد أسبوع كل موظف قبل الفوترة. الأسبوع المعتمد مقفل." />
        <nav class="mb-4 flex gap-1">
            <Link v-for="(l, k) in statuses" :key="k" :href="`/tasks/timesheets?status=${k}`" class="rounded-xl px-4 py-2 text-sm" :class="status === k ? 'bg-navy font-semibold text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'">{{ l }}</Link>
        </nav>
        <div class="card divide-y divide-slate-100">
            <div v-for="s in sheets" :key="s.id" class="flex flex-wrap items-center gap-4 px-5 py-3 text-sm">
                <Avatar :name="s.user.name" size="size-8 text-xs" />
                <div class="min-w-40"><p class="font-semibold text-slate-800">{{ s.user.name }}</p><p class="num text-xs text-slate-500" dir="ltr">{{ s.week_start }}</p></div>
                <div class="num"><p class="text-xs text-slate-500">المجموع</p><p class="font-bold text-navy">{{ minutesLabel(s.totals.minutes) || '0د' }}</p></div>
                <div class="num"><p class="text-xs text-slate-500">قابلة للفوترة</p><p class="font-semibold text-emerald-700">{{ minutesLabel(s.totals.billable_minutes) || '0د' }}</p></div>
                <p v-if="s.note" class="max-w-xs truncate text-xs text-slate-500" :title="s.note">«{{ s.note }}»</p>
                <p v-if="s.decided_by" class="text-xs text-slate-500">{{ s.decided_by }} · {{ formatDateTime(s.decided_at) }}</p>
                <div class="ms-auto flex gap-2">
                    <Link :href="`/my-work/time?user=${s.user.id}&week=${s.week_start}`" class="rounded-xl px-3 py-1.5 text-xs text-brand hover:bg-slate-50">التفاصيل</Link>
                    <template v-if="s.status === 'submitted'">
                        <Btn size="sm" variant="secondary" @click="decide(s, 'reject')"><X class="size-4" /> رفض</Btn>
                        <Btn size="sm" @click="decide(s, 'approve')"><Check class="size-4" /> اعتماد</Btn>
                    </template>
                    <Btn v-else size="sm" variant="ghost" @click="decide(s, 'reopen')"><RotateCcw class="size-4" /> إعادة فتح</Btn>
                </div>
            </div>
            <p v-if="!sheets.length" class="px-5 py-10 text-center text-sm text-slate-500">لا جداول هنا.</p>
        </div>
    </AppLayout>
</template>

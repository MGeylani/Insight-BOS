<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { UserPlus, AtSign, MessageSquare, MessageSquareWarning, CircleDot, CalendarDays, Bell, Sun, CheckCheck, Trash2, MailOpen, Mail } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Pagination from '@/components/Pagination.vue';
import MyWorkTabs from '@/components/tasks/MyWorkTabs.vue';
import { formatDateTime } from '@/lib/format';

const props = defineProps({ items: Object, filters: Object, counts: Object });
const icons = {
    'task.assigned': UserPlus, 'task.mentioned': AtSign, 'task.comment': MessageSquare, 'task.comment_assigned': MessageSquareWarning,
    'task.status': CircleDot, 'task.due': CalendarDays, 'task.reminder': Bell, 'task.digest': Sun, 'crm.reminder': Bell,
};
const kinds = { '': 'الكل', 'task.assigned': 'إسناد', 'task.mentioned': 'إشارات', 'task.comment': 'تعليقات', 'task.comment_assigned': 'تعليقات مسندة', 'task.reminder': 'تذكيرات' };
const keep = { preserveScroll: true, preserveState: true };
const go = (p) => router.get('/inbox', { ...props.filters, ...p, page: undefined }, keep);
const markAll = () => router.post('/notifications/read', {}, keep);
const read = (n, v = true) => router.post(v ? '/notifications/read' : '/notifications/unread', { ids: [n.id] }, keep);
const open = (n) => { if (!n.read) read(n); };
const clearRead = () => confirm('حذف كل الإشعارات المقروءة؟') && router.delete('/notifications', { data: { read_only: true }, ...keep });
const rows = computed(() => props.items.data);
</script>

<template>
    <AppLayout title="الوارد">
        <PageHeader title="الوارد" :subtitle="counts.unread ? `${counts.unread} غير مقروء` : 'كل شيء مقروء'">
            <template #actions>
                <Btn v-if="counts.unread" variant="secondary" @click="markAll"><CheckCheck class="size-4" /> تعليم الكل كمقروء</Btn>
                <Btn variant="ghost" title="حذف المقروءة" @click="clearRead"><Trash2 class="size-4" /></Btn>
            </template>
        </PageHeader>
        <MyWorkTabs />
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <button v-for="(l, k) in kinds" :key="k" type="button" class="rounded-lg px-3 py-1.5 text-sm" :class="(filters.kind ?? '') === k ? 'bg-navy text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'" @click="go({ kind: k || undefined })">{{ l }}</button>
            <label class="ms-auto flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" class="rounded" :checked="filters.unread" @change="go({ unread: $event.target.checked ? 1 : undefined })" /> غير المقروءة فقط</label>
        </div>
        <div class="card divide-y divide-slate-100 overflow-hidden">
            <div v-for="n in rows" :key="n.id" class="group flex items-start gap-3 px-5 py-3.5" :class="n.read ? '' : 'bg-sky/5'">
                <span class="mt-0.5 rounded-lg p-1.5" :class="n.read ? 'bg-slate-100 text-slate-400' : 'bg-brand/10 text-brand'"><component :is="icons[n.data.kind] ?? Bell" class="size-4" /></span>
                <Link :href="n.data.url || '#'" class="min-w-0 flex-1" @click="open(n)">
                    <p class="text-sm" :class="n.read ? 'text-slate-600' : 'font-semibold text-slate-800'">{{ n.data.title }}</p>
                    <p v-if="n.data.body" class="mt-0.5 line-clamp-2 text-sm text-slate-500">{{ n.data.body }}</p>
                    <p class="mt-0.5 text-xs text-slate-400">{{ formatDateTime(n.created_at) }}</p>
                </Link>
                <button type="button" class="rounded p-1 text-slate-400 opacity-0 hover:text-brand group-hover:opacity-100" :title="n.read ? 'تعليم كغير مقروء' : 'تعليم كمقروء'" @click="read(n, !n.read)">
                    <Mail v-if="n.read" class="size-4" /><MailOpen v-else class="size-4" />
                </button>
            </div>
            <p v-if="!rows.length" class="px-5 py-10 text-center text-sm text-slate-500">لا إشعارات.</p>
        </div>
        <Pagination :links="items.links" class="mt-4" />
    </AppLayout>
</template>

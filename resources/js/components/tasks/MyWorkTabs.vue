<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { Sun, CalendarDays, Timer, Inbox } from '@lucide/vue';
import { useAuth } from '@/lib/auth';

const page = usePage();
const { can } = useAuth();
const tabs = [
    { href: '/my-work', label: 'عملي', icon: Sun, perm: 'tasks.view' },
    { href: '/my-work/calendar', label: 'تقويمي', icon: CalendarDays, perm: 'tasks.view' },
    { href: '/my-work/time', label: 'وقتي', icon: Timer, perm: 'tasks.track_time' },
    { href: '/inbox', label: 'الوارد', icon: Inbox },
];
const active = (h) => page.url === h || page.url.startsWith(h + '?');
</script>

<template>
    <nav class="mb-6 flex gap-1 overflow-x-auto rounded-2xl border border-slate-200/80 bg-white p-1">
        <Link v-for="t in tabs.filter((x) => !x.perm || can(x.perm))" :key="t.href" :href="t.href" class="flex items-center gap-2 whitespace-nowrap rounded-xl px-4 py-2 text-sm transition"
            :class="active(t.href) ? 'bg-navy font-semibold text-white' : 'text-slate-600 hover:bg-slate-100'">
            <component :is="t.icon" class="size-4" /> {{ t.label }}
            <span v-if="t.href === '/inbox' && page.props.notifications_unread" class="num rounded-full px-1.5 text-xs" :class="active(t.href) ? 'bg-white/20' : 'bg-red-500 text-white'">{{ page.props.notifications_unread }}</span>
        </Link>
    </nav>
</template>

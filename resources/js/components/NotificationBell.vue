<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Bell } from '@lucide/vue';
import { formatDateTime } from '@/lib/format';

const page = usePage();
const unread = computed(() => page.props.notifications_unread ?? 0);
const open = ref(false);
const items = ref([]);
const loading = ref(false);

async function toggle() {
    open.value = !open.value;
    if (!open.value) return;
    loading.value = true;
    try {
        const r = await fetch('/notifications', { headers: { Accept: 'application/json' } });
        items.value = r.ok ? await r.json() : [];
    } catch { items.value = []; }
    loading.value = false;
}
function markAll() {
    router.post('/notifications/read', {}, { preserveScroll: true, preserveState: true, onSuccess: () => items.value.forEach((n) => (n.read = true)) });
}
function go(n) {
    open.value = false;
    if (!n.read) router.post('/notifications/read', { ids: [n.id] }, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <div class="relative">
        <button class="relative rounded-xl p-2.5 text-slate-500 hover:bg-slate-100 hover:text-navy" title="التنبيهات" @click="toggle">
            <Bell class="size-5" />
            <span v-if="unread" class="num absolute -top-0.5 -end-0.5 flex min-w-5 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">{{ unread > 99 ? '99+' : unread }}</span>
        </button>
        <div v-if="open" class="card absolute end-0 z-30 mt-2 w-80 overflow-hidden shadow-xl" @mouseleave="open = false">
            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-2.5">
                <p class="text-sm font-semibold text-navy">التنبيهات</p>
                <button v-if="unread" class="text-xs text-brand hover:underline" @click="markAll">تعليم الكل كمقروء</button>
            </div>
            <p v-if="loading" class="px-4 py-6 text-center text-sm text-slate-400">…</p>
            <ul v-else-if="items.length" class="max-h-96 divide-y divide-slate-100 overflow-y-auto">
                <li v-for="n in items" :key="n.id">
                    <Link :href="n.data.url || '#'" class="block px-4 py-3 text-sm hover:bg-slate-50" :class="{ 'bg-sky/5': !n.read }" @click="go(n)">
                        <p :class="n.read ? 'text-slate-600' : 'font-semibold text-slate-800'">{{ n.data.title }}</p>
                        <p v-if="n.data.body" class="mt-0.5 line-clamp-2 text-xs text-slate-500">{{ n.data.body }}</p>
                        <p class="mt-0.5 text-xs text-slate-400">{{ formatDateTime(n.created_at) }}</p>
                    </Link>
                </li>
            </ul>
            <p v-else class="px-4 py-6 text-center text-sm text-slate-400">لا توجد تنبيهات.</p>
            <Link href="/inbox" class="block border-t border-slate-100 px-4 py-2.5 text-center text-xs font-semibold text-brand hover:bg-slate-50" @click="open = false">صندوق الوارد</Link>
        </div>
    </div>
</template>

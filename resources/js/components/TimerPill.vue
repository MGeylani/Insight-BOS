<script setup>
// The running timer (server-side), visible on every page.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Square, Timer } from '@lucide/vue';

const page = usePage();
const timer = computed(() => page.props.timer);
const now = ref(Date.now());
let h;
onMounted(() => (h = setInterval(() => (now.value = Date.now()), 1000)));
onBeforeUnmount(() => clearInterval(h));
const elapsed = computed(() => {
    if (!timer.value) return '';
    const s = Math.max(0, Math.floor((now.value - new Date(timer.value.started_at).getTime()) / 1000));
    const pad = (n) => String(n).padStart(2, '0');
    return `${Math.floor(s / 3600)}:${pad(Math.floor((s % 3600) / 60))}:${pad(s % 60)}`;
});
const stop = () => router.post('/timer/stop', {}, { preserveScroll: true, preserveState: true });
</script>

<template>
    <div v-if="timer" class="flex items-center gap-2 rounded-xl bg-emerald-50 py-1 ps-3 pe-1 text-sm text-emerald-800 ring-1 ring-emerald-600/20">
        <Timer class="size-4 animate-pulse" />
        <span class="num font-semibold" dir="ltr">{{ elapsed }}</span>
        <Link v-if="timer.task" :href="`/tasks/${timer.task.id}`" class="hidden max-w-48 truncate hover:underline md:inline" :title="timer.task.title">{{ timer.task.code }} · {{ timer.task.title }}</Link>
        <button type="button" class="rounded-lg bg-emerald-600 p-1.5 text-white hover:bg-emerald-700" title="إيقاف المؤقت" @click="stop"><Square class="size-3.5" fill="currentColor" /></button>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { LogOut, Menu, X, UserCog, TriangleAlert, CircleCheck } from '@lucide/vue';
import NotificationBell from '@/components/NotificationBell.vue';
import { navigation } from '@/lib/navigation';
import { useAuth } from '@/lib/auth';
import { initials } from '@/lib/format';

defineProps({ title: String });

const page = usePage();
const { user, can } = useAuth();
const mobileOpen = ref(false);
const menuOpen = ref(false);

const sections = computed(() =>
    navigation
        .map((s) => ({ ...s, items: s.items.filter((i) => !i.perm || can(i.perm)) }))
        .filter((s) => s.items.length),
);

const isActive = (href) => href && (page.url === href || page.url.startsWith(href + '/') || page.url.startsWith(href + '?'));

const flash = computed(() => page.props.flash ?? {});
const toast = ref(null);
watch(flash, (f) => {
    if (f.success || f.error) {
        toast.value = f.success ? { type: 'success', text: f.success } : { type: 'error', text: f.error };
        setTimeout(() => (toast.value = null), 4500);
    }
}, { immediate: true, deep: true });

const missingRates = computed(() => page.props.alerts?.missing_rates ?? []);

router.on('navigate', () => { mobileOpen.value = false; menuOpen.value = false; });
const logout = () => router.post('/logout');
</script>

<template>
    <Head :title="title" />
    <div class="min-h-screen">
        <!-- Sidebar -->
        <aside
            class="brand-gradient fixed inset-y-0 start-0 z-40 flex w-72 flex-col text-white transition-transform lg:translate-x-0"
            :class="mobileOpen ? 'translate-x-0' : 'translate-x-full lg:translate-x-0'"
        >
            <div class="flex h-20 items-center justify-between px-6">
                <Link href="/dashboard"><img src="/images/logo-white.svg" alt="Insight Media" class="h-10" /></Link>
                <button class="rounded-lg p-1.5 hover:bg-white/10 lg:hidden" @click="mobileOpen = false"><X class="size-5" /></button>
            </div>
            <nav class="flex-1 space-y-6 overflow-y-auto px-4 pb-6">
                <div v-for="(section, i) in sections" :key="i">
                    <p v-if="section.label" class="mb-2 px-3 text-[11px] font-semibold tracking-wide text-white/50">{{ section.label }}</p>
                    <ul class="space-y-0.5">
                        <li v-for="item in section.items" :key="item.label">
                            <Link
                                v-if="!item.soon"
                                :href="item.href"
                                class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition"
                                :class="isActive(item.match ?? item.href) ? 'bg-white text-navy font-semibold shadow-lg shadow-navy-900/20' : 'text-white/85 hover:bg-white/10'"
                            >
                                <component :is="item.icon" class="size-[18px] shrink-0" />
                                {{ item.label }}
                            </Link>
                            <span v-else class="flex cursor-default items-center gap-3 rounded-xl px-3 py-2 text-sm text-white/40">
                                <component :is="item.icon" class="size-[18px] shrink-0" />
                                {{ item.label }}
                                <span class="ms-auto rounded-full bg-white/10 px-2 py-0.5 text-[10px] text-white/60">قريباً</span>
                            </span>
                        </li>
                    </ul>
                </div>
            </nav>
            <div class="border-t border-white/10 px-6 py-4 text-[11px] text-white/45">Deeper Insight, Greater Impact</div>
        </aside>
        <div v-if="mobileOpen" class="fixed inset-0 z-30 bg-navy-900/40 lg:hidden" @click="mobileOpen = false" />

        <!-- Main -->
        <div class="lg:ps-72">
            <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200/70 bg-white/85 px-4 backdrop-blur sm:px-8">
                <button class="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden" @click="mobileOpen = true"><Menu class="size-5" /></button>
                <img src="/images/logo.svg" alt="Insight Media" class="h-7 lg:hidden" />
                <NotificationBell class="ms-auto" />
                <div class="relative">
                    <button class="flex items-center gap-3 rounded-xl py-1.5 ps-1.5 pe-3 hover:bg-slate-100" @click="menuOpen = !menuOpen">
                        <span class="flex size-9 items-center justify-center rounded-full bg-navy text-sm font-semibold text-white">{{ initials(user?.name) }}</span>
                        <span class="hidden text-start sm:block">
                            <span class="block text-sm font-semibold leading-tight text-slate-800">{{ user?.name }}</span>
                            <span class="block text-xs text-slate-500">{{ user?.is_super_admin ? 'مدير النظام' : user?.email }}</span>
                        </span>
                    </button>
                    <div v-if="menuOpen" class="card absolute end-0 mt-2 w-56 overflow-hidden py-1 shadow-xl" @mouseleave="menuOpen = false">
                        <Link href="/profile" class="flex items-center gap-2 px-4 py-2.5 text-sm text-slate-700 hover:bg-slate-50"><UserCog class="size-4" /> حسابي</Link>
                        <button class="flex w-full items-center gap-2 px-4 py-2.5 text-sm text-red-600 hover:bg-red-50" @click="logout"><LogOut class="size-4" /> تسجيل الخروج</button>
                    </div>
                </div>
            </header>

            <div v-if="missingRates.length" class="border-b border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-900 sm:px-8">
                <TriangleAlert class="me-1 inline size-4 align-[-3px]" />
                لم يُدخل سعر صرف اليوم لـ <strong>{{ missingRates.join('، ') }}</strong>؛ تُستخدم آخر الأسعار المسجلة.
                <Link href="/settings/currencies" class="ms-2 font-semibold underline">إدخال الأسعار</Link>
            </div>

            <main class="mx-auto max-w-7xl px-4 py-8 sm:px-8">
                <slot />
            </main>
        </div>

        <Transition enter-from-class="opacity-0 translate-y-2" leave-to-class="opacity-0" enter-active-class="transition" leave-active-class="transition">
            <div
                v-if="toast"
                class="fixed bottom-6 start-6 z-50 flex max-w-sm items-start gap-2 rounded-xl px-4 py-3 text-sm text-white shadow-2xl"
                :class="toast.type === 'success' ? 'bg-navy' : 'bg-red-600'"
            >
                <CircleCheck v-if="toast.type === 'success'" class="mt-0.5 size-4 shrink-0 text-sky" />
                <TriangleAlert v-else class="mt-0.5 size-4 shrink-0" />
                {{ toast.text }}
            </div>
        </Transition>
    </div>
</template>

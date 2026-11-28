<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { LogOut, Menu, X, UserCog, TriangleAlert, CircleCheck, PanelRightClose, PanelRightOpen } from '@lucide/vue';
import NotificationBell from '@/components/NotificationBell.vue';
import TimerPill from '@/components/TimerPill.vue';
import { navigation } from '@/lib/navigation';
import { useAuth } from '@/lib/auth';
import { initials } from '@/lib/format';

defineProps({ title: String, wide: Boolean });

const page = usePage();
const { user, can } = useAuth();
const mobileOpen = ref(false);
const menuOpen = ref(false);

// Desktop: the sidebar folds to an icon rail; the browser remembers the choice. Mobile keeps the drawer.
const readPref = () => { try { return localStorage.getItem('bos.sidebar') === 'collapsed'; } catch { return false; } };
const collapsed = ref(readPref());
const toggleCollapsed = () => {
    collapsed.value = !collapsed.value;
    try { localStorage.setItem('bos.sidebar', collapsed.value ? 'collapsed' : 'open'); } catch { /* private mode */ }
};
// Rail tooltip: fixed-position so the scrolling nav does not clip it.
const tip = ref(null);
const showTip = (e, text) => {
    if (!collapsed.value || window.innerWidth < 1024) return;
    const r = e.currentTarget.getBoundingClientRect();
    tip.value = { text, top: r.top + r.height / 2, right: window.innerWidth - r.left + 8 };
};
const hideTip = () => (tip.value = null);

const sections = computed(() =>
    navigation
        .map((s) => ({ ...s, items: s.items.filter((i) => (user.value?.is_guest ? i.guest : (!i.perm || can(i.perm)) && !(i.unless && can(i.unless)))) }))
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

router.on('navigate', () => { mobileOpen.value = false; menuOpen.value = false; tip.value = null; });
const logout = () => router.post('/logout');
</script>

<template>
    <Head :title="title" />
    <div class="min-h-screen">
        <!-- Sidebar -->
        <aside
            class="brand-gradient fixed inset-y-0 start-0 z-40 flex w-72 flex-col text-white transition-[transform,width] duration-200 lg:translate-x-0"
            :class="[mobileOpen ? 'translate-x-0' : 'translate-x-full lg:translate-x-0', collapsed ? 'lg:w-[4.5rem]' : '']"
        >
            <div class="flex h-20 items-center justify-between px-6" :class="collapsed ? 'lg:justify-center lg:px-2' : ''">
                <Link href="/dashboard">
                    <img src="/images/logo-white.svg" alt="Insight Media" class="h-10" :class="collapsed ? 'lg:hidden' : ''" />
                    <img v-if="collapsed" src="/images/favicon.svg" alt="Insight Media" class="hidden size-9 rounded-lg bg-white p-1 lg:block" />
                </Link>
                <button class="rounded-lg p-1.5 hover:bg-white/10 lg:hidden" @click="mobileOpen = false"><X class="size-5" /></button>
            </div>
            <nav class="flex-1 space-y-6 overflow-y-auto overflow-x-hidden px-4 pb-6" :class="collapsed ? 'lg:space-y-3 lg:px-3' : ''" @scroll="hideTip">
                <div v-for="(section, i) in sections" :key="i">
                    <p v-if="section.label" class="mb-2 px-3 text-[11px] font-semibold tracking-wide text-white/50" :class="collapsed ? 'lg:hidden' : ''">{{ section.label }}</p>
                    <div v-if="section.label && collapsed" class="mx-2 mb-2 hidden border-t border-white/15 lg:block" />
                    <ul class="space-y-0.5">
                        <li v-for="item in section.items" :key="item.label">
                            <Link
                                v-if="!item.soon"
                                :href="item.href"
                                class="relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition"
                                :class="[isActive(item.match ?? item.href) ? 'bg-white text-navy font-semibold shadow-lg shadow-navy-900/20' : 'text-white/85 hover:bg-white/10', collapsed ? 'lg:justify-center lg:px-0' : '']"
                                :aria-label="item.label"
                                @mouseenter="showTip($event, item.label)" @mouseleave="hideTip"
                            >
                                <component :is="item.icon" class="size-[18px] shrink-0" />
                                <span :class="collapsed ? 'lg:hidden' : ''">{{ item.label }}</span>
                                <span v-if="item.badge && page.props[item.badge]" class="num ms-auto rounded-full bg-red-500 px-1.5 text-[10px] font-bold text-white"
                                    :class="collapsed ? 'lg:absolute lg:-top-0.5 lg:end-1 lg:ms-0' : ''">{{ page.props[item.badge] > 99 ? '99+' : page.props[item.badge] }}</span>
                            </Link>
                            <span v-else class="flex cursor-default items-center gap-3 rounded-xl px-3 py-2 text-sm text-white/40" :class="collapsed ? 'lg:justify-center lg:px-0' : ''"
                                @mouseenter="showTip($event, item.label + ' (قريباً)')" @mouseleave="hideTip">
                                <component :is="item.icon" class="size-[18px] shrink-0" />
                                <span :class="collapsed ? 'lg:hidden' : ''">{{ item.label }}</span>
                                <span class="ms-auto rounded-full bg-white/10 px-2 py-0.5 text-[10px] text-white/60" :class="collapsed ? 'lg:hidden' : ''">قريباً</span>
                            </span>
                        </li>
                    </ul>
                </div>
            </nav>
            <div class="border-t border-white/10 px-6 py-4 text-[11px] text-white/45" :class="collapsed ? 'lg:hidden' : ''">Deeper Insight, Greater Impact</div>
        </aside>
        <div v-if="mobileOpen" class="fixed inset-0 z-30 bg-navy-900/40 lg:hidden" @click="mobileOpen = false" />
        <div v-if="tip" class="pointer-events-none fixed z-50 -translate-y-1/2 whitespace-nowrap rounded-lg bg-navy-900 px-2.5 py-1.5 text-xs font-medium text-white shadow-lg"
            :style="{ top: tip.top + 'px', right: tip.right + 'px' }">{{ tip.text }}</div>

        <!-- Main -->
        <div class="transition-[padding] duration-200" :class="collapsed ? 'lg:ps-[4.5rem]' : 'lg:ps-72'">
            <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200/70 bg-white/85 px-4 backdrop-blur sm:px-8">
                <button class="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden" @click="mobileOpen = true"><Menu class="size-5" /></button>
                <button class="hidden rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-navy lg:block" :title="collapsed ? 'إظهار القائمة الجانبية' : 'طيّ القائمة الجانبية'" @click="toggleCollapsed">
                    <component :is="collapsed ? PanelRightOpen : PanelRightClose" class="size-5" />
                </button>
                <img src="/images/logo.svg" alt="Insight Media" class="h-7 lg:hidden" />
                <TimerPill class="ms-auto" />
                <NotificationBell :class="page.props.timer ? '' : 'ms-auto'" />
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

            <main class="mx-auto px-4 py-8 sm:px-8" :class="wide ? 'max-w-none' : 'max-w-7xl'">
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

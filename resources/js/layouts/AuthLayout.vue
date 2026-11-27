<script setup>
import { Head } from '@inertiajs/vue3';
defineProps({ title: String, subtitle: String });
</script>

<template>
    <Head :title="title" />
    <div class="grid min-h-screen lg:grid-cols-2">
        <div class="flex flex-col justify-center px-6 py-12 sm:px-16">
            <div class="mx-auto w-full max-w-sm">
                <img src="/images/logo.svg" alt="Insight Media" class="mb-10 h-12" />
                <h1 class="text-2xl font-bold text-navy">{{ title }}</h1>
                <p v-if="subtitle" class="mt-2 text-sm text-slate-500">{{ subtitle }}</p>
                <div class="mt-8"><slot /></div>
            </div>
        </div>
        <div class="brand-gradient relative hidden overflow-hidden lg:block">
            <img src="/images/logo-mark.svg" alt="" class="absolute -bottom-24 -start-24 w-[560px] opacity-[0.07] brightness-0 invert" />
            <div class="relative flex h-full flex-col justify-end p-14 text-white">
                <p class="text-sm tracking-widest text-sky">INSIGHT BOS</p>
                <p class="mt-3 text-4xl font-bold leading-tight">رؤية ثاقبة..<br />إبداع عابر</p>
                <p class="mt-4 max-w-md text-white/75">منظومة تشغيل Insight Media: العملاء والمشاريع والمهام والفريق والمالية في مكان واحد.</p>
            </div>
        </div>
    </div>
</template>

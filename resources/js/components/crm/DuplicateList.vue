<script setup>
import { Link } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';

defineProps({ items: Array, title: { type: String, default: 'سجلات مشابهة موجودة' } });
const kind = { lead: 'محتمل', client: 'عميل', contact: 'جهة اتصال' };
const on = { email: 'البريد', phone: 'الهاتف', company: 'اسم الشركة' };
</script>

<template>
    <div v-if="items?.length" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
        <p class="mb-2 flex items-center gap-1.5 font-semibold"><TriangleAlert class="size-4" /> {{ title }}</p>
        <ul class="space-y-1.5">
            <li v-for="d in items" :key="d.type + d.id" class="flex flex-wrap items-center gap-2">
                <span class="rounded-full bg-white/70 px-2 py-0.5 text-xs">{{ kind[d.type] }}</span>
                <Link :href="d.url" class="font-medium underline underline-offset-2">{{ d.name }}</Link>
                <span class="text-xs text-amber-800/80">تطابق: {{ d.matched_on.map((m) => on[m]).join('، ') }}</span>
                <slot name="action" :item="d" />
            </li>
        </ul>
    </div>
</template>

<script setup>
// Compact invoice list for client / project / contract pages.
import { Link } from '@inertiajs/vue3';
import { Plus, Receipt } from '@lucide/vue';
import Badge from '@/components/Badge.vue';
import { money } from '@/lib/crm';
import { formatDate } from '@/lib/format';
import { invoiceStatusTone } from '@/lib/finance';

defineProps({ invoices: Array, statuses: Object, createHref: { type: String, default: null }, title: { type: String, default: 'الفواتير' } });
</script>

<template>
    <div class="card p-5">
        <div class="mb-3 flex items-center gap-2">
            <Receipt class="size-4 text-slate-400" />
            <h3 class="me-auto font-semibold text-navy">{{ title }}</h3>
            <Link v-if="createHref" :href="createHref" class="flex items-center gap-1 text-xs text-brand hover:underline"><Plus class="size-3.5" /> فاتورة</Link>
        </div>
        <ul v-if="invoices.length" class="divide-y divide-slate-100 text-sm">
            <li v-for="i in invoices" :key="i.id" class="flex items-center gap-2 py-2">
                <Link :href="`/finance/invoices/${i.id}`" class="min-w-0 flex-1">
                    <p class="truncate font-medium text-slate-800 hover:text-brand"><span class="num text-navy">{{ i.number ?? 'مسودة' }}</span> · {{ i.title }}</p>
                    <p class="text-xs text-slate-500">{{ formatDate(i.issue_date) }}<template v-if="i.balance > 0 && i.status !== 'draft'"> · المتبقي <span class="num">{{ money(i.balance, i.currency) }}</span></template></p>
                </Link>
                <span class="num whitespace-nowrap text-xs font-semibold">{{ money(i.total, i.currency) }}</span>
                <Badge :tone="invoiceStatusTone[i.status]">{{ statuses[i.status] }}</Badge>
            </li>
        </ul>
        <p v-else class="text-sm text-slate-500">لا فواتير بعد.</p>
    </div>
</template>

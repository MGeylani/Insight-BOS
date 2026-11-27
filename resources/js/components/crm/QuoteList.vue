<script setup>
import { Link } from '@inertiajs/vue3';
import { Plus, FileText } from '@lucide/vue';
import Badge from '@/components/Badge.vue';
import { useAuth } from '@/lib/auth';
import { formatDate } from '@/lib/format';
import { money, quoteStatusTone } from '@/lib/crm';

defineProps({ quotes: Array, statuses: Object, createHref: String });
const { can } = useAuth();
</script>

<template>
    <div class="card p-5">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="font-semibold text-navy">عروض الأسعار</h3>
            <Link v-if="can('crm.create') && createHref" :href="createHref" class="inline-flex items-center gap-1 text-sm text-brand hover:underline"><Plus class="size-4" /> عرض سعر</Link>
        </div>
        <ul v-if="quotes.length" class="divide-y divide-slate-100">
            <li v-for="q in quotes" :key="q.id">
                <Link :href="`/crm/quotes/${q.id}`" class="-mx-2 flex items-center gap-3 rounded-lg px-2 py-2.5 hover:bg-slate-50">
                    <FileText class="size-4 shrink-0 text-slate-400" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-slate-800">{{ q.title }}</p>
                        <p class="text-xs text-slate-500"><span class="num">{{ q.number ?? 'مسودة' }}</span><template v-if="q.issue_date"> · {{ formatDate(q.issue_date) }}</template></p>
                    </div>
                    <div class="text-end">
                        <p class="num text-sm font-semibold text-navy">{{ money(q.total, q.currency) }}</p>
                        <Badge :tone="quoteStatusTone[q.status]">{{ statuses[q.status] }}</Badge>
                    </div>
                </Link>
            </li>
        </ul>
        <p v-else class="text-sm text-slate-500">لا توجد عروض بعد.</p>
    </div>
</template>

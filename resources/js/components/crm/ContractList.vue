<script setup>
import { Link } from '@inertiajs/vue3';
import { Plus, FileSignature } from '@lucide/vue';
import Badge from '@/components/Badge.vue';
import { useAuth } from '@/lib/auth';
import { formatDate } from '@/lib/format';
import { money, contractStatusTone } from '@/lib/crm';

defineProps({ contracts: Array, statuses: Object, createHref: String });
const { can } = useAuth();
</script>

<template>
    <div class="card p-5">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="font-semibold text-navy">العقود</h3>
            <Link v-if="can('crm.create') && createHref" :href="createHref" class="inline-flex items-center gap-1 text-sm text-brand hover:underline"><Plus class="size-4" /> عقد</Link>
        </div>
        <ul v-if="contracts.length" class="divide-y divide-slate-100">
            <li v-for="c in contracts" :key="c.id">
                <Link :href="`/crm/contracts/${c.id}`" class="-mx-2 flex items-center gap-3 rounded-lg px-2 py-2.5 hover:bg-slate-50">
                    <FileSignature class="size-4 shrink-0 text-slate-400" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-slate-800">{{ c.title }}</p>
                        <p class="text-xs text-slate-500"><span class="num">{{ c.number ?? 'مسودة' }}</span><template v-if="c.start_date"> · {{ formatDate(c.start_date) }}</template><template v-if="c.end_date"> ← {{ formatDate(c.end_date) }}</template></p>
                    </div>
                    <div class="text-end">
                        <p v-if="c.type === 'retainer'" class="num text-sm font-semibold text-navy">{{ money(c.monthly_fee, c.currency) }}<span class="text-xs font-normal text-slate-500"> / شهر</span></p>
                        <p v-else class="num text-sm font-semibold text-navy">{{ money(c.total, c.currency) }}</p>
                        <Badge :tone="contractStatusTone[c.status]">{{ statuses[c.status] }}</Badge>
                    </div>
                </Link>
            </li>
        </ul>
        <p v-else class="text-sm text-slate-500">لا توجد عقود بعد.</p>
    </div>
</template>

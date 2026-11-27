<script setup>
import { Link } from '@inertiajs/vue3';
import { Plus, Briefcase } from '@lucide/vue';
import Badge from '@/components/Badge.vue';
import { useAuth } from '@/lib/auth';
import { formatDate } from '@/lib/format';
import { projectStatusTone } from '@/lib/projects';

defineProps({ projects: Array, statuses: Object, createHref: String });
const { can } = useAuth();
</script>

<template>
    <div v-if="can('projects.view')" class="card p-5">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="font-semibold text-navy">المشاريع</h3>
            <Link v-if="can('projects.create') && createHref" :href="createHref" class="inline-flex items-center gap-1 text-sm text-brand hover:underline"><Plus class="size-4" /> مشروع</Link>
        </div>
        <ul v-if="projects.length" class="divide-y divide-slate-100">
            <li v-for="p in projects" :key="p.id">
                <Link :href="`/projects/${p.id}`" class="-mx-2 flex items-center gap-3 rounded-lg px-2 py-2.5 hover:bg-slate-50">
                    <Briefcase class="size-4 shrink-0 text-slate-400" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-slate-800">{{ p.name }}</p>
                        <p class="text-xs text-slate-500"><span class="num">{{ p.number }}</span><template v-if="p.due_date"> · التسليم {{ formatDate(p.due_date) }}</template></p>
                    </div>
                    <div class="text-end">
                        <p v-if="p.progress !== null" class="num text-xs text-slate-500">{{ p.progress }}%</p>
                        <Badge :tone="p.overdue ? 'red' : projectStatusTone[p.status]">{{ p.overdue ? 'متأخر' : statuses[p.status] }}</Badge>
                    </div>
                </Link>
            </li>
        </ul>
        <p v-else class="text-sm text-slate-500">لا توجد مشاريع بعد.</p>
    </div>
</template>

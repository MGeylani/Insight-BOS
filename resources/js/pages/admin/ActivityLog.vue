<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Badge from '@/components/Badge.vue';
import Pagination from '@/components/Pagination.vue';
import EmptyState from '@/components/EmptyState.vue';
import { formatDateTime } from '@/lib/format';

const props = defineProps({ logs: Object, filters: Object });
const action = ref(props.filters.action ?? '');
const groups = { '': 'الكل', auth: 'الدخول والخروج', users: 'المستخدمون', roles: 'القوالب', settings: 'الإعدادات' };
const filter = () => router.get('/admin/activity', { action: action.value || undefined }, { preserveState: true, replace: true });
const tone = (a) => (a.startsWith('auth') ? 'sky' : a.startsWith('users') || a.startsWith('roles') ? 'navy' : 'plum');
const open = ref(null);
</script>

<template>
    <AppLayout title="سجل التدقيق">
        <PageHeader title="سجل التدقيق" subtitle="كل عملية دخول وتعديل في النظام: من فعلها ومتى ومن أي عنوان." />
        <div class="card overflow-hidden">
            <div class="flex flex-wrap gap-2 border-b border-slate-100 p-4">
                <button v-for="(label, key) in groups" :key="key" class="rounded-full px-3.5 py-1.5 text-sm" :class="action === key ? 'bg-navy text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'" @click="action = key; filter()">{{ label }}</button>
            </div>
            <div class="overflow-x-auto">
                <table v-if="logs.data.length" class="tbl">
                    <thead><tr><th>الوقت</th><th>المستخدم</th><th>العملية</th><th>الوصف</th><th>IP</th></tr></thead>
                    <tbody>
                        <template v-for="l in logs.data" :key="l.id">
                            <tr class="cursor-pointer" @click="open = open === l.id ? null : l.id">
                                <td class="whitespace-nowrap text-slate-500">{{ formatDateTime(l.at) }}</td>
                                <td class="font-medium">{{ l.user ?? 'النظام' }}</td>
                                <td><Badge :tone="tone(l.action)"><span dir="ltr">{{ l.action }}</span></Badge></td>
                                <td class="text-slate-600">{{ l.description ?? '—' }}</td>
                                <td class="text-xs text-slate-400" dir="ltr">{{ l.ip }}</td>
                            </tr>
                            <tr v-if="open === l.id && l.changes">
                                <td colspan="5" class="bg-slate-50"><pre class="overflow-x-auto text-xs text-slate-600" dir="ltr">{{ JSON.stringify(l.changes, null, 2) }}</pre></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <EmptyState v-else title="لا توجد عمليات مسجلة" />
            </div>
            <Pagination :links="logs.links" />
        </div>
    </AppLayout>
</template>

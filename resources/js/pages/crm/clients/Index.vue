<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Plus, Search } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Badge from '@/components/Badge.vue';
import Pagination from '@/components/Pagination.vue';
import EmptyState from '@/components/EmptyState.vue';
import CrmTabs from '@/components/crm/CrmTabs.vue';
import ClientFormModal from '@/components/crm/ClientFormModal.vue';
import { useAuth } from '@/lib/auth';
import { initials } from '@/lib/format';

const props = defineProps({
    clients: Object, filters: Object,
    sources: Object, leadStages: Object, ratings: Object, oppStages: Array, lostReasons: Object,
    activityTypes: Object, clientTypes: Object, users: Array, currencies: Array,
});
const { can } = useAuth();
const options = computed(() => props);
const creating = ref(false);

const f = reactive({ search: props.filters.search ?? '', owner: props.filters.owner ?? '' });
let t;
watch(f, () => {
    clearTimeout(t);
    t = setTimeout(() => router.get('/crm/clients', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300);
});
</script>

<template>
    <AppLayout title="العملاء">
        <PageHeader title="العملاء والمبيعات" subtitle="ملف كل عميل: جهات الاتصال، الفرص، والمتابعات.">
            <template #actions><Btn v-if="can('crm.create')" @click="creating = true"><Plus class="size-4" /> عميل</Btn></template>
        </PageHeader>
        <CrmTabs />

        <div class="card overflow-hidden">
            <div class="flex flex-wrap gap-2 border-b border-slate-100 p-4">
                <div class="relative min-w-56 flex-1 sm:max-w-sm">
                    <Search class="absolute inset-y-0 start-3 my-auto size-4 text-slate-400" />
                    <input v-model="f.search" type="search" placeholder="اسم، بريد، هاتف" class="field-input ps-9" />
                </div>
                <select v-model="f.owner" class="field-input w-auto"><option value="">كل المسؤولين</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select>
            </div>
            <div class="overflow-x-auto">
                <table v-if="clients.data.length" class="tbl">
                    <thead><tr><th>العميل</th><th>جهة الاتصال الرئيسية</th><th>المدينة</th><th>فرص مفتوحة</th><th>مسؤول الحساب</th></tr></thead>
                    <tbody>
                        <tr v-for="c in clients.data" :key="c.id" class="cursor-pointer" @click="router.visit(`/crm/clients/${c.id}`)">
                            <td>
                                <div class="flex items-center gap-3">
                                    <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-navy-50 text-sm font-semibold text-navy">{{ initials(c.name) }}</span>
                                    <div><p class="font-medium text-slate-800">{{ c.name }}</p><p class="text-xs text-slate-500">{{ clientTypes[c.type] }}<template v-if="c.industry"> · {{ c.industry }}</template></p></div>
                                </div>
                            </td>
                            <td><template v-if="c.contact"><p>{{ c.contact.name }}</p><p class="text-xs text-slate-500" dir="ltr">{{ c.contact.phone }}</p></template><span v-else class="text-slate-400">—</span></td>
                            <td class="text-slate-600">{{ [c.city, c.country].filter(Boolean).join('، ') || '—' }}</td>
                            <td><Badge v-if="c.open_opportunities" tone="sky"><span class="num">{{ c.open_opportunities }}</span></Badge><span v-else class="text-slate-400">—</span></td>
                            <td class="text-slate-600">{{ c.owner?.name ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-else title="لا يوجد عملاء" text="حوّل عميلاً محتملاً أو أضف عميلاً مباشرة." />
            </div>
            <Pagination :links="clients.links" />
        </div>

        <ClientFormModal v-model="creating" :options="options" />
    </AppLayout>
</template>

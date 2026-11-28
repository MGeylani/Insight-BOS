<script setup>
import { ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Plus, Search, Pencil, Power } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Badge from '@/components/Badge.vue';
import Pagination from '@/components/Pagination.vue';
import EmptyState from '@/components/EmptyState.vue';
import { useAuth } from '@/lib/auth';
import { formatDateTime, initials } from '@/lib/format';

const props = defineProps({ users: Object, filters: Object });
const { can, user: me } = useAuth();
const search = ref(props.filters.search ?? '');

let t;
watch(search, (v) => {
    clearTimeout(t);
    t = setTimeout(() => router.get('/admin/users', { search: v || undefined }, { preserveState: true, replace: true }), 300);
});

const toggle = (u) => {
    if (confirm(u.is_active ? `إيقاف حساب ${u.name}؟ لن يتمكن من الدخول.` : `تفعيل حساب ${u.name}؟`)) {
        router.post(`/admin/users/${u.id}/toggle`, {}, { preserveScroll: true });
    }
};
</script>

<template>
    <AppLayout title="المستخدمون">
        <PageHeader title="المستخدمون" subtitle="الحسابات والصلاحيات. المسمى الوظيفي لا يمنح أي صلاحية؛ الصلاحيات تُمنح لكل شخص.">
            <template #actions>
                <Btn v-if="can('users.manage')" href="/admin/users/create"><Plus class="size-4" /> مستخدم جديد</Btn>
            </template>
        </PageHeader>

        <div class="card overflow-hidden">
            <div class="border-b border-slate-100 p-4">
                <div class="relative max-w-sm">
                    <Search class="absolute inset-y-0 start-3 my-auto size-4 text-slate-400" />
                    <input v-model="search" type="search" placeholder="ابحث بالاسم أو البريد" class="field-input ps-9" />
                </div>
            </div>
            <div class="overflow-x-auto">
                <table v-if="users.data.length" class="tbl">
                    <thead>
                        <tr><th>المستخدم</th><th>القالب المطبّق</th><th>الصلاحيات</th><th>آخر دخول</th><th>الحالة</th><th class="w-px"></th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="u in users.data" :key="u.id" :class="{ 'opacity-60': !u.is_active }">
                            <td>
                                <div class="flex items-center gap-3">
                                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-navy-50 text-sm font-semibold text-navy">{{ initials(u.name) }}</span>
                                    <div>
                                        <p class="font-medium text-slate-800">{{ u.name }}</p>
                                        <p class="text-xs text-slate-500" dir="ltr">{{ u.email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="text-slate-600">{{ u.template ?? '—' }}</td>
                            <td>
                                <Badge v-if="u.is_super_admin" tone="navy">مدير النظام · كل الصلاحيات</Badge>
                                <Badge v-if="u.is_guest" tone="amber">ضيف · يرى ما شوركه فقط</Badge>
                                <span v-else class="text-slate-600"><span class="num">{{ u.grants_count }}</span> صلاحية</span>
                            </td>
                            <td class="text-slate-500">{{ formatDateTime(u.last_login_at) }}</td>
                            <td><Badge :tone="u.is_active ? 'green' : 'red'">{{ u.is_active ? 'نشط' : 'موقوف' }}</Badge></td>
                            <td>
                                <div v-if="can('users.manage')" class="flex justify-end gap-1">
                                    <Link :href="`/admin/users/${u.id}/edit`" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-navy" title="تعديل"><Pencil class="size-4" /></Link>
                                    <button v-if="u.id !== me.id" class="rounded-lg p-2 hover:bg-slate-100" :class="u.is_active ? 'text-slate-500 hover:text-red-600' : 'text-emerald-600'" :title="u.is_active ? 'إيقاف' : 'تفعيل'" @click="toggle(u)"><Power class="size-4" /></button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-else title="لا يوجد مستخدمون مطابقون" />
            </div>
            <Pagination :links="users.links" />
        </div>
    </AppLayout>
</template>

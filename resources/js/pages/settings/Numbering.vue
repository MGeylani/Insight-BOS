<script setup>
import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import Btn from '@/components/Btn.vue';
import Toggle from '@/components/Toggle.vue';

const props = defineProps({ sequences: Array });
const rows = reactive(props.sequences.map((s) => ({ ...s, errors: {}, saving: false })));
const year = new Date().getFullYear();

const preview = (r) => [r.prefix || '—', r.yearly_reset ? year : null, String((r.last_number || 0) + 1).padStart(r.padding, '0')].filter(Boolean).join('-');

function save(r) {
    r.saving = true;
    router.put(`/settings/numbering/${r.id}`, { prefix: r.prefix, padding: r.padding, yearly_reset: r.yearly_reset }, {
        preserveScroll: true,
        onError: (e) => (r.errors = e),
        onSuccess: () => (r.errors = {}),
        onFinish: () => (r.saving = false),
    });
}
</script>

<template>
    <AppLayout title="ترقيم المستندات">
        <PageHeader title="ترقيم المستندات" subtitle="بادئة لكل نوع + السنة + تسلسل يبدأ من جديد كل سنة. الرقم يُمنح عند الإصدار لا عند المسودة، والمستند يُلغى ولا يُحذف، فلا تظهر فجوات." />
        <div class="card overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>المستند</th><th>البادئة</th><th>عدد الخانات</th><th>تصفير سنوي</th><th>الرقم التالي</th><th class="w-px"></th></tr></thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.id">
                        <td class="font-medium">{{ r.label }}</td>
                        <td>
                            <input v-model="r.prefix" dir="ltr" class="field-input w-28 text-start uppercase" maxlength="16" />
                            <p v-if="r.errors.prefix" class="field-error">{{ r.errors.prefix }}</p>
                        </td>
                        <td><input v-model.number="r.padding" type="number" min="3" max="8" dir="ltr" class="field-input w-20 text-start" /></td>
                        <td><Toggle v-model="r.yearly_reset" /></td>
                        <td><code class="rounded-lg bg-navy-50 px-2.5 py-1 text-sm font-semibold text-navy" dir="ltr">{{ preview(r) }}</code></td>
                        <td><Btn size="sm" variant="secondary" :loading="r.saving" @click="save(r)">حفظ</Btn></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

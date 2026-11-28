<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { ListChecks, Plus, Trash2, CornerDownLeft } from '@lucide/vue';
import Btn from '@/components/Btn.vue';
import ChecklistItems from './ChecklistItems.vue';
import { keep } from '@/lib/tasks';

const props = defineProps({ taskId: Number, checklists: Array, users: Array, editable: Boolean });
const newName = ref('');
const adding = ref(false);
const addChecklist = () => {
    if (!newName.value.trim()) return;
    router.post(`/tasks/${props.taskId}/checklists`, { name: newName.value }, { ...keep, onSuccess: () => { newName.value = ''; adding.value = false; } });
};
const rename = (c, name) => name.trim() && name !== c.name && router.put(`/tasks/checklists/${c.id}`, { name }, keep);
const remove = (c) => confirm(`حذف قائمة التحقق «${c.name}»؟`) && router.delete(`/tasks/checklists/${c.id}`, keep);
const stats = (c) => ({ done: c.items.filter((i) => i.done).length, total: c.items.length });
</script>

<template>
    <div class="card overflow-hidden">
        <div class="flex items-center gap-2 border-b border-slate-100 px-5 py-3">
            <ListChecks class="size-4 text-plum" /><h3 class="me-auto font-semibold text-navy">قوائم التحقق</h3>
            <Btn v-if="editable" size="sm" variant="secondary" @click="adding = true"><Plus class="size-4" /> قائمة تحقق</Btn>
        </div>
        <div v-for="c in checklists" :key="c.id" class="border-b border-slate-100 px-5 py-3 last:border-0">
            <div class="mb-2 flex items-center gap-2">
                <input :value="c.name" class="min-w-0 flex-1 bg-transparent font-medium text-slate-800 outline-none focus:ring-0" :readonly="!editable" @change="rename(c, $event.target.value)" />
                <span class="num text-xs text-slate-500">{{ stats(c).done }}/{{ stats(c).total }}</span>
                <button v-if="editable" type="button" class="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600" @click="remove(c)"><Trash2 class="size-4" /></button>
            </div>
            <div v-if="stats(c).total" class="mb-2 h-1 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-500" :style="{ width: (stats(c).done / stats(c).total) * 100 + '%' }" /></div>
            <ChecklistItems :checklist-id="c.id" :items="c.items" :parent-id="null" :users="users" :editable="editable" :level="0" />
        </div>
        <div v-if="adding" class="flex items-center gap-2 px-5 py-3">
            <input v-model="newName" class="field-input flex-1" placeholder="اسم قائمة التحقق" autofocus @keydown.enter="addChecklist" @keydown.esc="adding = false" />
            <Btn size="sm" @click="addChecklist"><CornerDownLeft class="size-4" /></Btn>
        </div>
        <p v-if="!checklists.length && !adding" class="px-5 py-4 text-sm text-slate-500">لا توجد قوائم تحقق.</p>
    </div>
</template>

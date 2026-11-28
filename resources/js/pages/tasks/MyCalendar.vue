<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import MyWorkTabs from '@/components/tasks/MyWorkTabs.vue';
import TaskCalendarView from '@/components/tasks/TaskCalendarView.vue';

defineProps({ tasks: Array, appointments: Array, can: Object });
</script>

<template>
    <AppLayout title="تقويمي">
        <PageHeader title="تقويمي" subtitle="مهامي المسندة ومواعيدي في CRM — يوم، أسبوع، شهر. اسحب المهمة لتغيير موعدها." />
        <MyWorkTabs />
        <TaskCalendarView :tasks="tasks" :can="can" :extra-events="appointments" />
        <p class="mt-2 text-xs text-slate-500">📅 = موعد أو نشاط من CRM (يُفتح في صفحته).</p>
    </AppLayout>
</template>

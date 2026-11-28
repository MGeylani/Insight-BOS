<?php

namespace Database\Seeders;

use App\Models\RoleTemplate;
use Illuminate\Database\Seeder;

class RoleTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $all = fn (array $keys) => array_fill_keys($keys, 'all');

        $templates = [
            'مدير مشاريع' => $all(['crm.view', 'projects.view', 'projects.create', 'projects.edit', 'projects.manage_budget', 'projects.approve_changes', 'tasks.view', 'tasks.create', 'tasks.edit', 'tasks.assign', 'tasks.track_time', 'tasks.approve_time', 'assets.view', 'comms.view', 'reports.view'])
                + ['hr.approve_leave' => 'assigned', 'finance.create' => 'own', 'finance.expense' => 'own', 'finance.approve' => 'assigned'],
            'مبيعات' => $all(['crm.view', 'crm.create', 'crm.edit', 'crm.export', 'crm.convert', 'comms.view'])
                + ['projects.view' => 'assigned', 'tasks.view' => 'own', 'tasks.edit' => 'own', 'tasks.track_time' => 'own'],
            'مالية' => $all(['finance.view', 'finance.create', 'finance.edit', 'finance.approve', 'finance.export', 'finance.expense', 'finance.delete', 'crm.view', 'projects.view', 'reports.view', 'reports.export', 'settings.exchange_rates', 'comms.view'])
                + ['tasks.view' => 'own', 'tasks.track_time' => 'own', 'hr.view_salaries' => 'all'],
            'موارد بشرية' => $all(['hr.view', 'hr.create', 'hr.edit', 'hr.approve_leave', 'hr.view_salaries', 'assets.view', 'assets.assign', 'comms.view', 'comms.announce'])
                + ['tasks.view' => 'own', 'tasks.track_time' => 'own'],
            'مسؤول أصول' => $all(['assets.view', 'assets.create', 'assets.edit', 'assets.assign', 'comms.view'])
                + ['projects.view' => 'all', 'tasks.view' => 'own', 'tasks.track_time' => 'own'],
            'موظف' => ['projects.view' => 'assigned', 'tasks.view' => 'assigned', 'tasks.create' => 'own', 'tasks.edit' => 'assigned', 'tasks.track_time' => 'own', 'comms.view' => 'all', 'finance.create' => 'own', 'finance.expense' => 'own'],
        ];

        foreach ($templates as $name => $perms) {
            RoleTemplate::firstOrCreate(['name' => $name], ['permissions' => $perms]);
        }
    }
}

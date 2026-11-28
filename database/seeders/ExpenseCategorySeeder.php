<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

/** Default expense categories for a media agency. Idempotent: adds missing names only. */
class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['إنتاج وتصوير', 'direct'], ['طباعة', 'direct'], ['إعلانات ممولة', 'direct'], ['مستقلون ومتعاقدون', 'direct'],
            ['مواد وخامات للمشاريع', 'direct'], ['مواصلات وسفر', 'direct'],
            ['إيجار ومرافق', 'overhead'], ['برمجيات واشتراكات', 'overhead'], ['معدات وصيانة', 'overhead'],
            ['ضيافة ومكتب', 'overhead'], ['رسوم بنكية وتحويل', 'overhead'], ['رواتب وأجور', 'overhead'], ['أخرى', 'overhead'],
        ];
        foreach ($rows as $i => [$name, $kind]) {
            ExpenseCategory::withTrashed()->firstOrCreate(['name' => $name], ['kind' => $kind, 'sort' => $i]);
        }
    }
}

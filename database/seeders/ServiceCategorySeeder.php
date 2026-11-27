<?php

namespace Database\Seeders;

use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

class ServiceCategorySeeder extends Seeder
{
    public function run(): void
    {
        if (ServiceCategory::exists()) {
            return;
        }
        $names = ['الهوية البصرية والتصميم', 'التسويق الرقمي والإعلانات', 'إدارة منصات التواصل', 'الإنتاج المرئي والتصوير', 'تطوير المواقع والتطبيقات', 'الطباعة والمعارض'];
        foreach ($names as $i => $name) {
            ServiceCategory::create(['name' => $name, 'tax_rate' => 0, 'sort' => $i]);
        }
    }
}

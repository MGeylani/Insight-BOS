<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Production-safe base data. Every seeder is idempotent and can be re-run on deploy.
     */
    public function run(): void
    {
        $this->call([
            CurrencySeeder::class,
            NumberSequenceSeeder::class,
            ServiceCategorySeeder::class,
            RoleTemplateSeeder::class,
            TaskSeeder::class,
            ExpenseCategorySeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}

<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'USD', 'name' => 'دولار أمريكي', 'symbol' => '$', 'decimals' => 2, 'is_base' => true],
            ['code' => 'SYP', 'name' => 'ليرة سورية', 'symbol' => 'ل.س', 'decimals' => 0, 'is_base' => false],
            ['code' => 'TRY', 'name' => 'ليرة تركية', 'symbol' => '₺', 'decimals' => 2, 'is_base' => false],
        ];
        foreach ($rows as $r) {
            Currency::firstOrCreate(['code' => $r['code']], $r + ['is_active' => true]);
        }
    }
}

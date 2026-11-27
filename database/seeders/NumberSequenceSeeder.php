<?php

namespace Database\Seeders;

use App\Models\NumberSequence;
use Illuminate\Database\Seeder;

class NumberSequenceSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['type' => 'invoice', 'label' => 'الفواتير', 'prefix' => 'INV'],
            ['type' => 'quote', 'label' => 'عروض الأسعار', 'prefix' => 'QT'],
            ['type' => 'receipt', 'label' => 'سندات القبض', 'prefix' => 'RCPT'],
            ['type' => 'expense', 'label' => 'المصروفات', 'prefix' => 'EXP'],
            ['type' => 'contract', 'label' => 'العقود', 'prefix' => 'CTR'],
            ['type' => 'project', 'label' => 'المشاريع', 'prefix' => 'PRJ'],
        ];
        foreach ($rows as $r) {
            NumberSequence::firstOrCreate(['type' => $r['type']], $r + ['padding' => 4, 'yearly_reset' => true]);
        }
    }
}

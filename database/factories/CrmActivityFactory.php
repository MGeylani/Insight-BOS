<?php

namespace Database\Factories;

use App\Models\CrmActivity;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CrmActivity> */
class CrmActivityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subject_type' => 'lead',
            'subject_id' => Lead::factory(),
            'type' => 'call',
            'title' => 'مكالمة متابعة',
        ];
    }
}

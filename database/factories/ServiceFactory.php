<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Service> */
class ServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'service_category_id' => fn () => ServiceCategory::create(['name' => 'تصنيف '.fake()->unique()->word(), 'tax_rate' => 0])->id,
            'name' => 'خدمة '.fake()->word(),
            'unit' => 'مشروع',
            'price' => 500,
            'currency' => 'USD',
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Lead> */
class LeadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'company_name' => fake()->company(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+90 5'.fake()->numerify('## ### ## ##'),
            'source' => 'website',
            'stage' => 'new',
            'rating' => 'warm',
            'expected_value' => 1500,
            'currency' => 'USD',
        ];
    }
}

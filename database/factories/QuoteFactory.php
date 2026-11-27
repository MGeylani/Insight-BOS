<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Quote> */
class QuoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'title' => 'عرض '.fake()->word(),
            'currency' => 'USD',
            'valid_until' => now()->addDays(30)->toDateString(),
        ];
    }
}

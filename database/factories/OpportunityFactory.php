<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Opportunity;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Opportunity> */
class OpportunityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'title' => 'حملة '.fake()->word(),
            'stage' => 'discovery',
            'probability' => 10,
            'amount' => 2000,
            'currency' => 'USD',
            'expected_close_date' => now()->addMonth()->toDateString(),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Contract> */
class ContractFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => 'project',
            'client_id' => Client::factory(),
            'title' => 'عقد '.fake()->word(),
            'currency' => 'USD',
            'value' => 1000,
            'start_date' => today()->toDateString(),
        ];
    }
}

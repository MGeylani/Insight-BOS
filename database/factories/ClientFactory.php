<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Client> */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'type' => 'company',
            'email' => fake()->unique()->companyEmail(),
            'phone' => '+90 5'.fake()->numerify('## ### ## ##'),
            'currency' => 'USD',
            'source' => 'referral',
        ];
    }
}

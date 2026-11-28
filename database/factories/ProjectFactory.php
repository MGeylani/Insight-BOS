<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'مشروع '.fake()->word(),
            'type' => 'identity',
            'client_id' => Client::factory(),
            'status' => 'active',
            'currency' => 'USD',
            'budget' => 1500,
            'start_date' => today()->toDateString(),
            'due_date' => today()->addDays(30)->toDateString(),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'status' => 'pending',
            'progress' => 0,
        ];
    }

    public function completed(): static
    {
        return $this->state([
            'status' => 'completed',
            'progress' => 100,
            'worker_hostname' => 'worker-1',
            'started_at' => now()->subMinutes(2),
            'completed_at' => now()->subMinute(),
        ]);
    }

    public function processing(): static
    {
        return $this->state([
            'status' => 'processing',
            'progress' => fake()->numberBetween(10, 90),
            'worker_hostname' => 'worker-1',
            'started_at' => now()->subSeconds(30),
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Models\ServerActionLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerActionLog>
 */
class ServerActionLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'action_type' => fake()->randomElement(ServerActionLog::allowedActions()),
            'status' => 'success',
            'output' => "[INFO] Execution completed successfully on Windows Laragon Server.\nExitCode: 0",
            'started_at' => now()->subSeconds(5),
            'finished_at' => now(),
        ];
    }
}

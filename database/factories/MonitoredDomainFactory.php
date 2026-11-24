<?php

namespace Database\Factories;

use App\Models\MonitoredDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitoredDomain>
 */
class MonitoredDomainFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $days = fake()->numberBetween(15, 85);

        return [
            'domain_name' => fake()->unique()->domainName(),
            'port' => 443,
            'issuer' => "Let's Encrypt (R3)",
            'ssl_valid_from' => now()->subDays(10),
            'ssl_expires_at' => now()->addDays($days),
            'days_remaining' => $days,
            'status' => 'valid',
            'last_checked_at' => now(),
            'last_reminded_at' => null,
            'is_active' => true,
        ];
    }

    /**
     * State for a critical SSL domain (<= 7 days).
     */
    public function critical(int $days = 3): static
    {
        return $this->state(fn (array $attributes) => [
            'ssl_expires_at' => now()->addDays($days),
            'days_remaining' => $days,
            'status' => 'critical',
        ]);
    }

    /**
     * State for a warning SSL domain (8 - 14 days).
     */
    public function warning(int $days = 10): static
    {
        return $this->state(fn (array $attributes) => [
            'ssl_expires_at' => now()->addDays($days),
            'days_remaining' => $days,
            'status' => 'warning',
        ]);
    }
}

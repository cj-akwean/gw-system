<?php

namespace Database\Factories;

use App\Models\MeterReading;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MeterReadingFactory extends Factory
{
    protected $model = MeterReading::class;

    public function definition(): array
    {
        $previous = fake()->randomFloat(2, 0, 100);
        $present = $previous + fake()->randomFloat(2, 0, 50);

        return [
            'service_connection_id' => ServiceConnection::factory(),
            'present_reading' => $present,
            'previous_reading' => $previous,
            'cu_m_used' => $present - $previous,
            'entered_by' => User::factory(),
            'entered_at' => now(),
            'method' => fake()->randomElement(['manual', 'csv_import']),
            'flagged' => fake()->boolean(5),
        ];
    }

    public function flagged(): static
    {
        return $this->state(fn (array $attributes) => [
            'flagged' => true,
        ]);
    }
}

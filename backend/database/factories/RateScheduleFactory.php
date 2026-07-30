<?php

namespace Database\Factories;

use App\Models\RateSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

class RateScheduleFactory extends Factory
{
    protected $model = RateSchedule::class;

    public function definition(): array
    {
        return [
            'name' => fake()->word() . ' Rate ' . fake()->year(),
            'type' => 'flat',
            'flat_rate' => fake()->randomFloat(2, 5, 20),
            'effective_from' => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'effective_to' => null,
        ];
    }

    public function tiered(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'tiered',
            'flat_rate' => null,
        ]);
    }
}

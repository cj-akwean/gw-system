<?php

namespace Database\Factories;

use App\Models\PenaltyRule;
use Illuminate\Database\Eloquent\Factories\Factory;

class PenaltyRuleFactory extends Factory
{
    protected $model = PenaltyRule::class;

    public function definition(): array
    {
        return [
            'percent_per_month' => 2.00,
            'grace_period_days' => 15,
            'disconnection_after_days' => 60,
            'effective_from' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'effective_to' => null,
        ];
    }
}

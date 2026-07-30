<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\MeterReading;
use App\Models\RateSchedule;
use App\Models\ServiceConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    protected static int $counter = 0;

    public function definition(): array
    {
        static::$counter++;

        $baseAmount = fake()->randomFloat(2, 100, 5000);
        $penaltyAmount = fake()->optional(0.3)->randomFloat(2, 10, 200) ?? 0;

        return [
            'invoice_number' => 'GW-' . now()->format('Y') . '-' . str_pad((string) static::$counter, 5, '0', STR_PAD_LEFT),
            'service_connection_id' => ServiceConnection::factory(),
            'meter_reading_id' => MeterReading::factory(),
            'rate_schedule_id' => RateSchedule::factory(),
            'billing_period_start' => fake()->dateTimeBetween('-3 months', '-2 months')->format('Y-m-d'),
            'billing_period_end' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'previous_balance' => fake()->optional(0.4)->randomFloat(2, 0, 1000) ?? 0,
            'base_amount' => $baseAmount,
            'penalty_amount' => $penaltyAmount,
            'total_amount' => $baseAmount + $penaltyAmount,
            'due_date' => fake()->dateTimeBetween('now', '+1 month')->format('Y-m-d'),
            'status' => fake()->randomElement(['unpaid', 'paid', 'overdue']),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paid',
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'overdue',
            'due_date' => now()->subDays(rand(1, 30))->format('Y-m-d'),
        ]);
    }
}

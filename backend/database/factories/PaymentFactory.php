<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'amount' => fake()->randomFloat(2, 100, 5000),
            'method' => fake()->randomElement(['paymongo', 'cash', 'bank_transfer']),
            'paymongo_reference' => fn (array $attributes) => $attributes['method'] === 'paymongo'
                ? 'pay_' . fake()->uuid()
                : null,
            'paid_at' => now(),
        ];
    }

    public function paymongo(): static
    {
        return $this->state(fn (array $attributes) => [
            'method' => 'paymongo',
            'paymongo_reference' => 'pay_' . fake()->uuid(),
        ]);
    }

    public function cash(): static
    {
        return $this->state(fn (array $attributes) => [
            'method' => 'cash',
            'paymongo_reference' => null,
        ]);
    }
}

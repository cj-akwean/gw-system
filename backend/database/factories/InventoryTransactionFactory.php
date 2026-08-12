<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

class InventoryTransactionFactory extends Factory
{
    protected $model = InventoryTransaction::class;

    public function definition(): array
    {
        return [
            'inventory_item_id' => InventoryItem::factory(),
            'type' => fake()->randomElement(['receipt', 'issue']),
            'quantity' => fake()->randomFloat(3, 1, 100),
            'reference' => null,
            'reason' => null,
            'recorded_by' => null,
            'moved_at' => now()->toDateString(),
        ];
    }

    public function receipt(): static
    {
        return $this->state(fn (array $attributes) => ['type' => 'receipt']);
    }

    public function issue(): static
    {
        return $this->state(fn (array $attributes) => ['type' => 'issue']);
    }

    public function adjustment(float $quantity): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'adjustment',
            'quantity' => $quantity,
            'reason' => 'Physical count correction',
        ]);
    }
}

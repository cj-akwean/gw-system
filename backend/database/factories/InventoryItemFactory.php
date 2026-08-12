<?php

namespace Database\Factories;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class InventoryItemFactory extends Factory
{
    protected $model = InventoryItem::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'inventory_category_id' => InventoryCategory::factory(),
            'unit' => fake()->randomElement(['pc', 'm', 'roll', 'bag', 'set']),
            'quantity_on_hand' => fake()->randomFloat(3, 0, 500),
            'reorder_level' => fake()->randomFloat(3, 5, 50),
            'low_stock_alerted_at' => null,
        ];
    }

    public function lowStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity_on_hand' => 2,
            'reorder_level' => 10,
        ]);
    }

    public function withTransactions(int $count = 3, ?int $recordedBy = null): static
    {
        return $this->afterCreating(function (InventoryItem $item) use ($count, $recordedBy): void {
            InventoryTransaction::factory()->count($count)->create([
                'inventory_item_id' => $item->id,
                'recorded_by' => $recordedBy,
            ]);
        });
    }
}

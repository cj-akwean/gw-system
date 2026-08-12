<?php

namespace Database\Factories;

use App\Models\InventoryCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class InventoryCategoryFactory extends Factory
{
    protected $model = InventoryCategory::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Pipes', 'Fittings', 'Valves', 'Meters', 'Sealants & Tapes', 'Chemicals', 'Tools & Equipment', 'Hardware & Fasteners', 'Misc']),
        ];
    }
}

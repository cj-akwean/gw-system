<?php

namespace Database\Seeders;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;

class InventoryItemSeeder extends Seeder
{
    /**
     * Starter catalog for the office — categories are lookup data (admins can
     * add more anytime); items mirror what a water utility actually stocks.
     * Every item gets an opening receipt so the ledger and quantity_on_hand
     * agree from day one. recorded_by stays null (no admin session).
     */
    public function run(): void
    {
        $categories = collect([
            'Pipes',
            'Fittings',
            'Valves',
            'Meters',
            'Sealants & Tapes',
            'Chemicals',
            'Tools & Equipment',
            'Hardware & Fasteners',
            'Misc',
        ])->mapWithKeys(fn (string $name): array => [
            $name => InventoryCategory::firstOrCreate(
                ['name' => $name],
                ['name' => $name],
            )->id,
        ]);

        $items = [
            // Pipes
            ['Lion PVC Pipe 40mm × 40mm — ₱3,240', 'Pipes', 'm', 150, 30],
            ['PVC Pipe ½″', 'Pipes', 'm', 200, 40],
            ['PVC Pipe ¾″', 'Pipes', 'm', 200, 40],
            ['PVC Pipe 1″', 'Pipes', 'm', 100, 20],
            ['GI Pipe ½″', 'Pipes', 'm', 60, 15],
            // Fittings
            ['PVC Elbow ½″', 'Fittings', 'pc', 120, 25],
            ['PVC Tee ¾″', 'Fittings', 'pc', 80, 20],
            ['PVC Coupling 1″', 'Fittings', 'pc', 60, 15],
            ['GI Union ¾″', 'Fittings', 'pc', 40, 10],
            // Valves
            ['Gate Valve ½″', 'Valves', 'pc', 25, 8],
            ['Ball Valve ¾″', 'Valves', 'pc', 30, 8],
            // Meters
            ['Water Meter ½″', 'Meters', 'pc', 4, 5],
            // Sealants & Tapes
            ['Teflon Tape', 'Sealants & Tapes', 'roll', 50, 10],
            // Chemicals
            ['Sodium Hypochlorite (Chlorine)', 'Chemicals', 'L', 60, 20],
            ['Alum', 'Chemicals', 'kg', 100, 30],
            // Tools & Equipment
            ['Pipe Wrench 14″', 'Tools & Equipment', 'pc', 5, 2],
            ['PVC Pipe Cutter', 'Tools & Equipment', 'pc', 4, 2],
            // Hardware & Fasteners
            ['Clamp Bracket', 'Hardware & Fasteners', 'pc', 100, 25],
            // Misc
            ['Weld Rod', 'Misc', 'pc', 10, 3],
        ];

        foreach ($items as [$name, $category, $unit, $quantity, $reorder]) {
            $item = InventoryItem::firstOrCreate(
                ['name' => $name],
                [
                    'inventory_category_id' => $categories[$category],
                    'unit' => $unit,
                    'quantity_on_hand' => $quantity,
                    'reorder_level' => $reorder,
                ],
            );

            if ($item->transactions()->doesntExist()) {
                InventoryTransaction::create([
                    'inventory_item_id' => $item->id,
                    'type' => 'receipt',
                    'quantity' => $quantity,
                    'reference' => 'Opening stock',
                    'recorded_by' => null,
                    'moved_at' => now()->toDateString(),
                ]);
            }
        }

        // Seeded items can start below their reorder level (e.g. Water Meter
        // at 4 of 5) — surface them in the bell right away so the admin
        // doesn't have to wait for the 07:00 digest (or a cron that may not
        // exist on dev). No-op when everything is healthy.
        app(InventoryService::class)->notifyLowStockSummary();
    }
}

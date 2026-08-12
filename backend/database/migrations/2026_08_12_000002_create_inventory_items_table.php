<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('inventory_category_id')->constrained()->restrictOnDelete();
            $table->string('unit', 20)->default('pc');
            $table->decimal('quantity_on_hand', 12, 3)->default(0);
            $table->decimal('reorder_level', 12, 3)->default(0);
            $table->timestamp('low_stock_alerted_at')->nullable();
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX inventory_items_name_unique ON inventory_items (LOWER(name))');
        DB::statement('ALTER TABLE inventory_items ADD CONSTRAINT inventory_items_quantity_on_hand_non_negative CHECK (quantity_on_hand >= 0)');
        DB::statement('ALTER TABLE inventory_items ADD CONSTRAINT inventory_items_reorder_level_non_negative CHECK (reorder_level >= 0)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS inventory_items_name_unique');
        DB::statement('ALTER TABLE inventory_items DROP CONSTRAINT IF EXISTS inventory_items_quantity_on_hand_non_negative');
        DB::statement('ALTER TABLE inventory_items DROP CONSTRAINT IF EXISTS inventory_items_reorder_level_non_negative');

        Schema::dropIfExists('inventory_items');
    }
};

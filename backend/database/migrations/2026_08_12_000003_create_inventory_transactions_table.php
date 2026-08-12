<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->string('type', 12);
            $table->decimal('quantity', 12, 3);
            $table->string('reference', 100)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('moved_at');
            $table->timestamps();

            $table->index(['inventory_item_id', 'moved_at']);
        });

        DB::statement('ALTER TABLE inventory_transactions ADD CONSTRAINT inventory_transactions_quantity_non_zero CHECK (quantity <> 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE inventory_transactions DROP CONSTRAINT IF EXISTS inventory_transactions_quantity_non_zero');

        Schema::dropIfExists('inventory_transactions');
    }
};

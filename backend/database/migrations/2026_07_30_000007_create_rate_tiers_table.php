<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_schedule_id')->constrained()->cascadeOnDelete();
            $table->decimal('min_cu_m', 10, 2);
            $table->decimal('max_cu_m', 10, 2)->nullable();
            $table->decimal('rate_per_cu_m', 10, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_tiers');
    }
};

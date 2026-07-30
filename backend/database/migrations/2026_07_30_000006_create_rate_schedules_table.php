<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('type', 20);
            $table->decimal('flat_rate', 10, 4)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_schedules');
    }
};

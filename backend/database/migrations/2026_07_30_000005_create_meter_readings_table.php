<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meter_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_connection_id')->constrained()->cascadeOnDelete();
            $table->decimal('present_reading', 10, 2);
            $table->decimal('previous_reading', 10, 2);
            $table->decimal('cu_m_used', 10, 2);
            $table->foreignId('entered_by')->constrained('users');
            $table->timestamp('entered_at');
            $table->string('method', 20);
            $table->boolean('flagged')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meter_readings');
    }
};

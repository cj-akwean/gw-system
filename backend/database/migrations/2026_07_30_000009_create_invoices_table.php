<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 20)->unique();
            $table->foreignId('service_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meter_reading_id')->constrained();
            $table->foreignId('rate_schedule_id')->constrained();
            $table->date('billing_period_start');
            $table->date('billing_period_end');
            $table->decimal('previous_balance', 12, 2)->default(0);
            $table->decimal('base_amount', 12, 2);
            $table->decimal('penalty_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);
            $table->date('due_date');
            $table->string('status', 20)->default('unpaid');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};

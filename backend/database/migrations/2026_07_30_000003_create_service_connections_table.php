<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_connections', function (Blueprint $table) {
            $table->id();
            $table->string('account_number', 20)->unique();
            $table->string('meter_number', 20)->unique();
            $table->string('registered_name', 255);
            $table->foreignId('barangay_id')->constrained();
            $table->string('address', 255);
            $table->string('status', 20)->default('active');
            $table->date('connection_date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_connections');
    }
};

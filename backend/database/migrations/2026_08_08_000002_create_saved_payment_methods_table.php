<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('paymongo_payment_method_id', 100);
            $table->string('brand', 30)->nullable();
            $table->string('last4', 4);
            $table->unsignedTinyInteger('exp_month');
            $table->unsignedSmallInteger('exp_year');
            $table->string('payer_name', 255)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'paymongo_payment_method_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_payment_methods');
    }
};

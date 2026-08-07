<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_connections', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->after('address');
            $table->string('email', 255)->nullable()->after('phone');
            $table->string('gender', 20)->nullable()->after('email');
            $table->date('birthdate')->nullable()->after('gender');
            $table->string('civil_status', 20)->nullable()->after('birthdate');
            $table->string('occupation', 100)->nullable()->after('civil_status');
        });
    }

    public function down(): void
    {
        Schema::table('service_connections', function (Blueprint $table) {
            $table->dropColumn(['phone', 'email', 'gender', 'birthdate', 'civil_status', 'occupation']);
        });
    }
};

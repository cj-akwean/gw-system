<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('payer_name', 255)->nullable()->after('paymongo_source');
            $table->string('payer_email', 255)->nullable()->after('payer_name');
            $table->string('payer_phone', 40)->nullable()->after('payer_email');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['payer_name', 'payer_email', 'payer_phone']);
        });
    }
};

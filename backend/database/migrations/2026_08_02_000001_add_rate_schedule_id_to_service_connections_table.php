<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_connections', function (Blueprint $table) {
            $table->foreignId('rate_schedule_id')
                ->nullable()
                ->after('connection_date')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_connections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rate_schedule_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_connections', function (Blueprint $table) {
            $table->foreignId('imported_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_connections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('imported_by');
        });
    }
};

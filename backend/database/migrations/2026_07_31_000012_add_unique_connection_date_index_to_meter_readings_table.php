<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX meter_readings_connection_date_unique '
            .'ON meter_readings (service_connection_id, (entered_at::date));'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX meter_readings_connection_date_unique;');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE meter_readings ALTER COLUMN flagged DROP DEFAULT');
        DB::statement('ALTER TABLE meter_readings ALTER COLUMN flagged TYPE smallint USING flagged::int');
        DB::statement('ALTER TABLE meter_readings ALTER COLUMN flagged SET DEFAULT 0');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE meter_readings ALTER COLUMN flagged TYPE boolean USING flagged::int::boolean');
        DB::statement('ALTER TABLE meter_readings ALTER COLUMN flagged SET DEFAULT false');
    }
};

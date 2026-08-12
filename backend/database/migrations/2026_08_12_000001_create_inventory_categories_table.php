<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX inventory_categories_name_unique ON inventory_categories (LOWER(name))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS inventory_categories_name_unique');

        Schema::dropIfExists('inventory_categories');
    }
};

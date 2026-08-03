<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_runs', function (Blueprint $table) {
            $table->id();
            $table->date('period_end');
            $table->string('status', 10)->default('running');
            $table->jsonb('report')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        DB::statement("CREATE UNIQUE INDEX billing_runs_period_end_running_unique ON billing_runs (period_end) WHERE status = 'running'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS billing_runs_period_end_running_unique');

        Schema::dropIfExists('billing_runs');
    }
};

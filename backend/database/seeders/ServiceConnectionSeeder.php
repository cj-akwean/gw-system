<?php

namespace Database\Seeders;

use App\Models\RateSchedule;
use App\Models\ServiceConnection;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ServiceConnectionSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $rateSchedule = RateSchedule::query()->orderBy('effective_from')->first();

        ServiceConnection::factory()->count(15)->create([
            'rate_schedule_id' => $rateSchedule?->id,
        ]);
    }
}

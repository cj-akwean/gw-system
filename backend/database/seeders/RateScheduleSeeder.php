<?php

namespace Database\Seeders;

use App\Models\RateSchedule;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RateScheduleSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        RateSchedule::create([
            'name' => 'Standard Flat Rate',
            'type' => 'flat',
            'flat_rate' => 10.00,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
    }
}

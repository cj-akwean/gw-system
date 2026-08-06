<?php

namespace Database\Seeders;

use App\Models\PenaltyRule;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PenaltyRuleSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (PenaltyRule::query()->exists()) {
            return;
        }

        PenaltyRule::create([
            'percent_per_month' => 2.00,
            'grace_period_days' => 15,
            'disconnection_after_days' => 60,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
    }
}

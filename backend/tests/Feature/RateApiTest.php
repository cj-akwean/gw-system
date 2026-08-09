<?php

namespace Tests\Feature;

use App\Models\PenaltyRule;
use App\Models\RateSchedule;
use App\Models\RateTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_rates_endpoint_returns_current_schedule_and_penalty(): void
    {
        RateSchedule::factory()->create([
            'name' => 'Standard Flat Rate',
            'type' => 'flat',
            'flat_rate' => 10.00,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);
        PenaltyRule::factory()->create([
            'percent_per_month' => 2.00,
            'grace_period_days' => 15,
            'disconnection_after_days' => 60,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);

        $this->getJson('/api/rates')
            ->assertOk()
            ->assertJson([
                'schedule' => [
                    'name' => 'Standard Flat Rate',
                    'type' => 'flat',
                    'flat_rate' => 10.0,
                    'tiers' => [],
                ],
                'penalty' => [
                    'percent_per_month' => 2.0,
                    'grace_period_days' => 15,
                    'disconnection_after_days' => 60,
                ],
            ])
            ->assertJsonStructure([
                'schedule' => ['name', 'type', 'flat_rate', 'effective_from', 'tiers'],
                'penalty' => ['percent_per_month', 'grace_period_days', 'disconnection_after_days'],
            ]);
    }

    public function test_tiered_schedule_includes_tiers_sorted_by_minimum(): void
    {
        $schedule = RateSchedule::factory()->tiered()->create([
            'effective_from' => now()->subMonth()->toDateString(),
        ]);

        RateTier::create([
            'rate_schedule_id' => $schedule->id,
            'min_cu_m' => 21,
            'max_cu_m' => 40,
            'rate_per_cu_m' => 12.00,
        ]);
        RateTier::create([
            'rate_schedule_id' => $schedule->id,
            'min_cu_m' => 0,
            'max_cu_m' => 20,
            'rate_per_cu_m' => 10.00,
        ]);

        $this->getJson('/api/rates')
            ->assertOk()
            ->assertJsonPath('schedule.type', 'tiered')
            ->assertJsonPath('schedule.flat_rate', null)
            ->assertJsonPath('schedule.tiers.0.min_cu_m', 0)
            ->assertJsonPath('schedule.tiers.1.min_cu_m', 21);
    }

    public function test_returns_404_when_no_schedule_is_in_effect(): void
    {
        RateSchedule::factory()->create([
            'effective_from' => now()->addMonth()->toDateString(),
        ]);

        $this->getJson('/api/rates')
            ->assertStatus(404)
            ->assertJson(['message' => 'No rate schedule is currently in effect.']);
    }

    public function test_expired_schedule_is_not_returned(): void
    {
        RateSchedule::factory()->create([
            'effective_from' => now()->subMonths(3)->toDateString(),
            'effective_to' => now()->subMonth()->toDateString(),
        ]);

        $this->getJson('/api/rates')->assertStatus(404);
    }
}

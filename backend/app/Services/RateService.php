<?php

namespace App\Services;

use App\Models\PenaltyRule;
use App\Models\RateSchedule;
use App\Models\RateTier;
use Illuminate\Support\Collection;

class RateService
{
    /**
     * The schedule in effect today (effective_from <= today and no effective_to
     * or effective_to >= today), most recent first.
     */
    public function currentSchedule(): ?RateSchedule
    {
        return RateSchedule::query()
            ->whereDate('effective_from', '<=', today())
            ->where(function ($query) {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', today());
            })
            ->latest('effective_from')
            ->first();
    }

    public function currentPenaltyRule(): ?PenaltyRule
    {
        return PenaltyRule::query()
            ->whereDate('effective_from', '<=', today())
            ->where(function ($query) {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', today());
            })
            ->latest('effective_from')
            ->first();
    }

    /**
     * Normalized payload for the public rates endpoint. Returns null when no
     * schedule is in effect so the controller can 404.
     */
    public function publicPayload(): ?array
    {
        $schedule = $this->currentSchedule();

        if (! $schedule) {
            return null;
        }

        /** @var Collection<int, RateTier> $tiers */
        $tiers = $schedule->tiers()->orderBy('min_cu_m')->get();

        $penalty = $this->currentPenaltyRule();

        return [
            'schedule' => [
                'name' => $schedule->name,
                'type' => $schedule->type,
                'flat_rate' => $schedule->flat_rate !== null ? (float) $schedule->flat_rate : null,
                'effective_from' => $schedule->effective_from?->toDateString(),
                'tiers' => $tiers->map(fn (RateTier $tier) => [
                    'min_cu_m' => (float) $tier->min_cu_m,
                    'max_cu_m' => $tier->max_cu_m !== null ? (float) $tier->max_cu_m : null,
                    'rate_per_cu_m' => (float) $tier->rate_per_cu_m,
                ])->values(),
            ],
            'penalty' => $penalty ? [
                'percent_per_month' => (float) $penalty->percent_per_month,
                'grace_period_days' => (int) $penalty->grace_period_days,
                'disconnection_after_days' => (int) $penalty->disconnection_after_days,
            ] : null,
        ];
    }
}

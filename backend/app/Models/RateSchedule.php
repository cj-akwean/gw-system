<?php

namespace App\Models;

use Database\Factories\RateScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'type', 'flat_rate', 'effective_from', 'effective_to'])]
class RateSchedule extends Model
{
    /** @use HasFactory<RateScheduleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function tiers(): HasMany
    {
        return $this->hasMany(RateTier::class);
    }
}

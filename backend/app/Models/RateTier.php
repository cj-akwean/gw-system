<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['rate_schedule_id', 'min_cu_m', 'max_cu_m', 'rate_per_cu_m'])]
class RateTier extends Model
{
    public function rateSchedule(): BelongsTo
    {
        return $this->belongsTo(RateSchedule::class);
    }
}

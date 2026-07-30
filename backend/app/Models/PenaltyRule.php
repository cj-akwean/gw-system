<?php

namespace App\Models;

use Database\Factories\PenaltyRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['percent_per_month', 'grace_period_days', 'disconnection_after_days', 'effective_from', 'effective_to'])]
class PenaltyRule extends Model
{
    /** @use HasFactory<PenaltyRuleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }
}

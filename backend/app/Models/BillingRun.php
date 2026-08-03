<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['period_end', 'status', 'report', 'error', 'finished_at'])]
class BillingRun extends Model
{
    public const STALE_AFTER = '10 hours';

    public function isStale(): bool
    {
        return $this->created_at !== null
            && $this->created_at->lessThan(now()->sub(self::STALE_AFTER));
    }
    protected function casts(): array
    {
        return [
            'period_end' => 'date',
            'report' => 'array',
            'finished_at' => 'datetime',
        ];
    }
}

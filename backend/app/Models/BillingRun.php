<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['period_end', 'status', 'report', 'error', 'finished_at'])]
class BillingRun extends Model
{
    protected function casts(): array
    {
        return [
            'period_end' => 'date',
            'report' => 'array',
            'finished_at' => 'datetime',
        ];
    }
}

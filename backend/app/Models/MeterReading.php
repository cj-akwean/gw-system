<?php

namespace App\Models;

use Database\Factories\MeterReadingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['service_connection_id', 'present_reading', 'previous_reading', 'cu_m_used', 'entered_by', 'entered_at', 'method', 'flagged'])]
class MeterReading extends Model
{
    /** @use HasFactory<MeterReadingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'present_reading' => 'decimal:2',
            'previous_reading' => 'decimal:2',
            'cu_m_used' => 'decimal:2',
            'entered_at' => 'datetime',
            'flagged' => 'boolean',
        ];
    }

    public function serviceConnection(): BelongsTo
    {
        return $this->belongsTo(ServiceConnection::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}

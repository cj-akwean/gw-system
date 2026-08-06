<?php

namespace App\Models;

use Database\Factories\ServiceConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_number', 'meter_number', 'registered_name', 'barangay_id', 'address', 'status', 'connection_date', 'rate_schedule_id'])]
class ServiceConnection extends Model
{
    /** @use HasFactory<ServiceConnectionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'connection_date' => 'date',
        ];
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    public function rateSchedule(): BelongsTo
    {
        return $this->belongsTo(RateSchedule::class);
    }

    public function connectionLinks(): HasMany
    {
        return $this->hasMany(ConnectionLink::class);
    }

    public function meterReadings(): HasMany
    {
        return $this->hasMany(MeterReading::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function scopeWithPendingBalance(Builder $query): Builder
    {
        return $query->withSum(
            ['invoices as pending_balance' => fn (Builder $q): Builder => $q->whereIn('status', ['unpaid', 'overdue'])],
            'total_amount',
        );
    }
}

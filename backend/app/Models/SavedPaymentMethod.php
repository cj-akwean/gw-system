<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'paymongo_payment_method_id', 'brand', 'last4',
    'exp_month', 'exp_year', 'payer_name',
])]
class SavedPaymentMethod extends Model
{
    /** @use HasFactory<\Database\Factories\SavedPaymentMethodFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'exp_month' => 'integer',
            'exp_year' => 'integer',
        ];
    }

    public function getIsExpiredAttribute(): bool
    {
        $now = now();

        return $this->exp_year < $now->year
            || ($this->exp_year === $now->year && $this->exp_month < $now->month);
    }

    public function getDisplayBrandAttribute(): ?string
    {
        return $this->brand !== null ? ucfirst($this->brand) : null;
    }

    public function getDisplayLabelAttribute(): string
    {
        $brand = $this->display_brand;
        $last4 = $this->last4;
        $expiry = sprintf('%02d/%02d', $this->exp_month, $this->exp_year % 100);

        return $brand !== null
            ? "{$brand} •••• {$last4}  Exp {$expiry}"
            : "•••• {$last4}  Exp {$expiry}";
    }
}

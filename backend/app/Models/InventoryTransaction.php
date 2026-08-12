<?php

namespace App\Models;

use Database\Factories\InventoryTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['inventory_item_id', 'type', 'quantity', 'reference', 'reason', 'recorded_by', 'moved_at'])]
class InventoryTransaction extends Model
{
    /** @use HasFactory<InventoryTransactionFactory> */
    use HasFactory;

    public const TYPES = ['receipt', 'issue', 'adjustment'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'moved_at' => 'date',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Signed movement: receipts are positive, issues are negative,
     * adjustments keep their own sign.
     */
    protected function signedQuantity(): Attribute
    {
        return Attribute::get(function (): float {
            $quantity = (float) $this->quantity;

            return match ($this->type) {
                'issue' => -$quantity,
                'receipt' => $quantity,
                default => $quantity,
            };
        });
    }
}

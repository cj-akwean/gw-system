<?php

namespace App\Models;

use Database\Factories\InventoryItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'inventory_category_id', 'unit', 'quantity_on_hand', 'reorder_level', 'low_stock_alerted_at'])]
class InventoryItem extends Model
{
    /** @use HasFactory<InventoryItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'decimal:3',
            'reorder_level' => 'decimal:3',
            'low_stock_alerted_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(InventoryCategory::class, 'inventory_category_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    public function isLowStock(): bool
    {
        return (float) $this->quantity_on_hand < (float) $this->reorder_level;
    }

    /**
     * Case-insensitive duplicate check (mirrors the LOWER(name) unique
     * index) — shared by the resource form rule.
     */
    public static function isNameTaken(string $name, ?int $exceptId = null): bool
    {
        return static::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();
    }

    /**
     * Three-state stock status for badges/filters:
     * no_stock (qty 0) → low_stock (0 < qty < reorder) → ok.
     */
    public function status(): string
    {
        if ((float) $this->quantity_on_hand <= 0) {
            return 'no_stock';
        }

        if ($this->isLowStock()) {
            return 'low_stock';
        }

        return 'ok';
    }
}

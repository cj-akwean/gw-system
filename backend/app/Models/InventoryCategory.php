<?php

namespace App\Models;

use Database\Factories\InventoryCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class InventoryCategory extends Model
{
    /** @use HasFactory<InventoryCategoryFactory> */
    use HasFactory;

    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class, 'inventory_category_id');
    }

    /**
     * Case-insensitive duplicate check (mirrors the LOWER(name) unique
     * index) — shared by the resource form rule and the inline
     * createOptionForm on the item form.
     */
    public static function isNameTaken(string $name, ?int $exceptId = null): bool
    {
        return static::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();
    }
}

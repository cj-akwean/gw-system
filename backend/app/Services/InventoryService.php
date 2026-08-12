<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * All inventory stock movements go through this service — never through the
 * model or Filament directly. Each mutation writes an audit ledger row
 * (inventory_transactions) and keeps the denormalized quantity_on_hand in
 * sync inside a lockForUpdate transaction, so two concurrent issues cannot
 * both pass an overdraw check.
 *
 * Low-stock alerting: fires only on the boundary crossing (not-low → low),
 * so an item that stays low doesn't spam the bell on every issue. The daily
 * inventory:check-low-stock digest is the safety net for anything missed.
 *
 * Inventory notifications are delivered SYNCHRONOUSLY (Notification::sendNow,
 * bypassing the ShouldQueue contract Filament's DatabaseNotification carries)
 * on purpose: the office must see the bell ring the moment stock drops, even
 * if the queue worker is down — unlike bulk billing emails, there is no
 * "catch up later" for a missing low-stock alert, and the volume is trivial.
 * The Notification Hub is the audit trail either way.
 */
class InventoryService
{
    /**
     * @param  float  $quantity  receipt/issue: positive; adjustment: signed (≠ 0)
     * @param  string|null  $reference  supplier / PO / work order, optional
     * @param  string|null  $reason  required for adjustments
     * @param  int|null  $recordedBy  admin user id (null for seeder/CLI rows)
     * @param  string|null  $movedAt  Y-m-d, defaults to today
     */
    public function recordTransaction(
        InventoryItem|int $item,
        string $type,
        float $quantity,
        ?string $reference = null,
        ?string $reason = null,
        ?int $recordedBy = null,
        ?string $movedAt = null,
        bool $alert = true,
    ): InventoryTransaction {
        if (! in_array($type, InventoryTransaction::TYPES, true)) {
            throw new InvalidArgumentException("Invalid transaction type: {$type}");
        }

        $this->validateQuantity($type, $quantity);

        if ($type === 'adjustment' && ($reason === null || trim($reason) === '')) {
            throw new InvalidArgumentException('An adjustment requires a reason.');
        }

        $movedAt = $movedAt ?? now()->toDateString();
        if ($movedAt > now()->toDateString()) {
            throw new InvalidArgumentException('Movement date cannot be in the future.');
        }

        $signed = match ($type) {
            'receipt' => $quantity,
            'issue' => -$quantity,
            default => $quantity,
        };

        return DB::transaction(function () use ($item, $type, $quantity, $signed, $reference, $reason, $recordedBy, $movedAt, $alert): InventoryTransaction {
            /** @var InventoryItem $locked */
            $locked = InventoryItem::query()->lockForUpdate()->findOrFail(
                $item instanceof InventoryItem ? $item->getKey() : $item,
            );

            $previous = (float) $locked->quantity_on_hand;
            $next = round($previous + $signed, 3);

            if ($next < 0) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot %s %s %s — only %s available.',
                    $type === 'issue' ? 'issue' : 'adjust down to',
                    rtrim(rtrim(sprintf('%.3f', abs($quantity)), '0'), '.'),
                    $locked->unit,
                    rtrim(rtrim(sprintf('%.3f', $previous), '0'), '.'),
                ));
            }

            $transaction = InventoryTransaction::create([
                'inventory_item_id' => $locked->id,
                'type' => $type,
                'quantity' => $quantity,
                'reference' => $reference !== null && trim($reference) !== '' ? trim($reference) : null,
                'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
                'recorded_by' => $recordedBy,
                'moved_at' => $movedAt,
            ]);

            $locked->update(['quantity_on_hand' => $next]);

            if ($alert) {
                $this->applyLowStockAlert($locked, $previous, $next);
            }

            return $transaction;
        });
    }

    /**
     * Items currently below their reorder level.
     *
     * @return Collection<int, InventoryItem>
     */
    public function lowStockItems(): Collection
    {
        return InventoryItem::query()
            ->with('category')
            ->whereColumn('quantity_on_hand', '<', 'reorder_level')
            ->orderBy('name')
            ->get();
    }

    /**
     * ONE aggregate admin notification listing every currently-low item —
     * the shared digest used by the daily command, the seeder, and any
     * catch-up path. Returns whether anything was notified.
     */
    public function notifyLowStockSummary(): bool
    {
        $items = $this->lowStockItems();

        if ($items->isEmpty()) {
            return false;
        }

        $this->notifyAdminsSync(
            title: $items->count() === 1
                ? '1 item below reorder level'
                : $items->count().' items below reorder level',
            body: $items->map(fn (InventoryItem $item): string => sprintf(
                '%s — %s %s (reorder: %s)',
                $item->name,
                trim(rtrim(sprintf('%.3f', (float) $item->quantity_on_hand), '0'), '.'),
                $item->unit,
                trim(rtrim(sprintf('%.3f', (float) $item->reorder_level), '0'), '.'),
            ))->implode(' • '),
            actionLabel: 'Open inventory',
            actionPath: '/admin/inventory-items',
            actionName: 'open-inventory',
        );

        return true;
    }

    /**
     * Recomputes quantity_on_hand from the ledger. With $fix = true the
     * corrected values are persisted (used by inventory:check-low-stock --fix);
     * otherwise returns the computed map for reporting only.
     *
     * @return array<int, float> item id → ledger-derived quantity
     */
    public function reconcileQuantities(bool $fix = false): array
    {
        $sums = InventoryTransaction::query()
            ->selectRaw('inventory_item_id, SUM(CASE WHEN type = \'issue\' THEN -quantity ELSE quantity END) AS total')
            ->groupBy('inventory_item_id')
            ->pluck('total', 'inventory_item_id')
            ->map(fn ($total): float => max(0.0, round((float) $total, 3)));

        if ($fix) {
            foreach ($sums as $itemId => $quantity) {
                InventoryItem::query()->whereKey($itemId)->update(['quantity_on_hand' => $quantity]);
            }
        }

        return $sums->all();
    }

    private function validateQuantity(string $type, float $quantity): void
    {
        if (! is_finite($quantity)) {
            throw new InvalidArgumentException('Quantity must be a finite number.');
        }

        if ($type === 'adjustment') {
            if ($quantity == 0) {
                throw new InvalidArgumentException('An adjustment quantity cannot be zero.');
            }
        } elseif ($quantity <= 0) {
            throw new InvalidArgumentException("A {$type} quantity must be greater than zero.");
        }

        if (abs($quantity - round($quantity, 3)) > 1e-9) {
            throw new InvalidArgumentException('Quantities allow at most 3 decimal places.');
        }
    }

    private function applyLowStockAlert(InventoryItem $item, float $previous, float $next): void
    {
        $reorder = (float) $item->reorder_level;

        if ($next >= $reorder) {
            if ($item->low_stock_alerted_at !== null) {
                $item->update(['low_stock_alerted_at' => null]);
            }

            return;
        }

        if ($previous < $reorder) {
            // Continuously low — the daily digest re-surfaces it; no spam.
            return;
        }

        $item->update(['low_stock_alerted_at' => now()]);

        $this->notifyAdminsSync(
            title: "Low stock: {$item->name}",
            body: sprintf(
                'Only %s %s left (reorder level: %s).',
                rtrim(rtrim(sprintf('%.3f', $next), '0'), '.'),
                $item->unit,
                rtrim(rtrim(sprintf('%.3f', $reorder), '0'), '.'),
            ),
            actionLabel: 'View item',
            actionPath: "/admin/inventory-items/{$item->id}",
            actionName: 'view-item',
        );
    }

    /**
     * Sends the SAME persistent Filament database notification AdminNotifier
     * produces (bell + hub), but synchronously — Notification::sendNow skips
     * the ShouldQueue contract, so the row lands in `notifications` even when
     * no queue worker is running. See the class docblock for why inventory
     * deliberately deviates from the queued AdminNotifier path.
     */
    private function notifyAdminsSync(string $title, string $body, string $actionLabel, string $actionPath, string $actionName): void
    {
        $admins = User::query()->where('is_admin', true)->get();

        if ($admins->isEmpty()) {
            return;
        }

        $notification = FilamentNotification::make()
            ->title($title)
            ->body($body)
            ->warning()
            ->actions([
                Action::make($actionName)
                    ->label($actionLabel)
                    ->button()
                    ->color('primary')
                    ->url($actionPath),
            ]);

        Notification::sendNow($admins, $notification->toDatabase());
    }
}

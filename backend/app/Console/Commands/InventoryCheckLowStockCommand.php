<?php

namespace App\Console\Commands;

use App\Services\InventoryService;
use Illuminate\Console\Command;

/**
 * Daily low-stock digest — the safety net behind the immediate
 * boundary-crossing alerts InventoryService fires on stock changes. Scans
 * every item below its reorder level and sends ONE aggregate admin
 * notification (title carries the count, body lists the items) so the bell
 * never floods per-item. --dry-run reports without notifying; --fix
 * recomputes quantity_on_hand from the ledger first (drift repair).
 * NOTE: this only fires on the host cron (`php artisan schedule:run` every
 * minute) — on dev without cron, run it manually after seeding or a big
 * import; the seeder already calls the same digest.
 */
class InventoryCheckLowStockCommand extends Command
{
    protected $signature = 'inventory:check-low-stock {--dry-run : Report low-stock items without notifying} {--fix : Recompute quantities from the ledger before checking}';

    protected $description = 'Notify admins (one aggregate notification) of inventory items below their reorder level.';

    public function handle(InventoryService $inventory): int
    {
        if ($this->option('fix')) {
            $reconciled = $inventory->reconcileQuantities(fix: true);
            $this->info(sprintf('Recomputed quantities for %d item(s) from the ledger.', count($reconciled)));
        }

        $items = $inventory->lowStockItems();

        if ($items->isEmpty()) {
            $this->info('inventory:check-low-stock OK — no items below reorder level.');

            return self::SUCCESS;
        }

        $this->warn(sprintf('%d item(s) below reorder level:', $items->count()));
        foreach ($items as $item) {
            $this->line(sprintf(
                '  %s — %s %s (reorder: %s)',
                $item->name,
                trim(rtrim(sprintf('%.3f', (float) $item->quantity_on_hand), '0'), '.'),
                $item->unit,
                trim(rtrim(sprintf('%.3f', (float) $item->reorder_level), '0'), '.'),
            ));
        }

        if ($this->option('dry-run')) {
            $this->line('Dry run — no notification sent.');

            return self::SUCCESS;
        }

        $inventory->notifyLowStockSummary();

        return self::SUCCESS;
    }
}

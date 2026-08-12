<?php

namespace Tests\Feature;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function item(array $attributes = []): InventoryItem
    {
        return InventoryItem::factory()->create(array_merge([
            'inventory_category_id' => InventoryCategory::factory(),
            'quantity_on_hand' => 100,
            'reorder_level' => 20,
        ], $attributes));
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function service(): InventoryService
    {
        return app(InventoryService::class);
    }

    public function test_receipt_increases_quantity_and_writes_ledger_row(): void
    {
        $item = $this->item();
        $admin = $this->admin();

        $transaction = $this->service()->recordTransaction(
            item: $item,
            type: 'receipt',
            quantity: 25.5,
            reference: 'PO #12',
            recordedBy: $admin->id,
        );

        $this->assertSame('receipt', $transaction->type);
        $this->assertSame('125.500', $item->fresh()->quantity_on_hand);
        $this->assertSame($admin->id, $transaction->recorded_by);
        $this->assertSame('PO #12', $transaction->reference);
        $this->assertDatabaseCount('inventory_transactions', 1);
    }

    public function test_issue_decreases_quantity_and_writes_ledger_row(): void
    {
        $item = $this->item(['quantity_on_hand' => 50]);
        $admin = $this->admin();

        $this->service()->recordTransaction(
            item: $item,
            type: 'issue',
            quantity: 12,
            reference: 'Work order #8',
            recordedBy: $admin->id,
        );

        $this->assertSame('38.000', $item->fresh()->quantity_on_hand);
        $this->assertSame('issue', $item->fresh()->transactions->first()->type);
        $this->assertSame($admin->id, $item->fresh()->transactions->first()->recorded_by);
    }

    public function test_issue_cannot_exceed_available_quantity(): void
    {
        $item = $this->item(['quantity_on_hand' => 5]);

        try {
            $this->service()->recordTransaction(item: $item, type: 'issue', quantity: 10);
            $this->fail('Expected InvalidArgumentException for overdraw.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('only 5 available', $e->getMessage());
        }

        $this->assertSame('5.000', $item->fresh()->quantity_on_hand);
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_issue_exactly_available_quantity_is_allowed(): void
    {
        $item = $this->item(['quantity_on_hand' => 5]);

        $this->service()->recordTransaction(item: $item, type: 'issue', quantity: 5);

        $this->assertSame('0.000', $item->fresh()->quantity_on_hand);
    }

    public function test_adjustment_can_correct_up_or_down_with_reason(): void
    {
        $item = $this->item(['quantity_on_hand' => 50]);

        $this->service()->recordTransaction(
            item: $item,
            type: 'adjustment',
            quantity: 3,
            reason: 'Found 3 extra in storage',
        );
        $this->assertSame('53.000', $item->fresh()->quantity_on_hand);

        $this->service()->recordTransaction(
            item: $item,
            type: 'adjustment',
            quantity: -8,
            reason: 'Physical count correction',
        );
        $this->assertSame('45.000', $item->fresh()->quantity_on_hand);
    }

    public function test_adjustment_requires_a_reason(): void
    {
        $item = $this->item();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a reason');

        $this->service()->recordTransaction(item: $item, type: 'adjustment', quantity: 5);
    }

    public function test_adjustment_cannot_drive_quantity_below_zero(): void
    {
        $item = $this->item(['quantity_on_hand' => 3]);

        try {
            $this->service()->recordTransaction(
                item: $item,
                type: 'adjustment',
                quantity: -10,
                reason: 'Physical count correction',
            );
            $this->fail('Expected InvalidArgumentException for negative adjustment.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('only 3 available', $e->getMessage());
        }

        $this->assertSame('3.000', $item->fresh()->quantity_on_hand);
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_receipt_and_issue_quantities_must_be_positive(): void
    {
        $item = $this->item();

        foreach (['receipt', 'issue'] as $type) {
            try {
                $this->service()->recordTransaction(item: $item, type: $type, quantity: 0);
                $this->fail('Expected InvalidArgumentException for zero quantity.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('greater than zero', $e->getMessage());
            }
        }

        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_adjustment_quantity_cannot_be_zero(): void
    {
        $item = $this->item();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be zero');

        $this->service()->recordTransaction(item: $item, type: 'adjustment', quantity: 0, reason: 'oops');
    }

    public function test_quantities_are_limited_to_three_decimal_places(): void
    {
        $item = $this->item();

        $this->service()->recordTransaction(item: $item, type: 'receipt', quantity: 1.234);
        $this->assertSame('101.234', $item->fresh()->quantity_on_hand);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('3 decimal places');

        $this->service()->recordTransaction(item: $item, type: 'receipt', quantity: 1.2345);
    }

    public function test_future_movement_date_is_rejected(): void
    {
        $item = $this->item();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('future');

        $this->service()->recordTransaction(
            item: $item,
            type: 'receipt',
            quantity: 5,
            movedAt: now()->addDay()->toDateString(),
        );
    }

    public function test_unknown_transaction_type_is_rejected(): void
    {
        $item = $this->item();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid transaction type');

        $this->service()->recordTransaction(item: $item, type: 'transfer', quantity: 5);
    }

    public function test_low_stock_alert_fires_when_crossing_below_reorder_level(): void
    {
        $this->admin();
        $item = $this->item(['quantity_on_hand' => 25, 'reorder_level' => 20]);

        $this->service()->recordTransaction(item: $item, type: 'issue', quantity: 10);

        $notification = \Illuminate\Notifications\DatabaseNotification::first();
        $this->assertNotNull($notification);
        $this->assertSame("Low stock: {$item->name}", $notification->data['title']);
        $this->assertStringContainsString('15', $notification->data['body']);
        $this->assertStringContainsString("/admin/inventory-items/{$item->id}", $notification->data['actions'][0]['url'] ?? '');
        $this->assertNotNull($item->fresh()->low_stock_alerted_at);
    }

    public function test_no_alert_while_quantity_stays_below_reorder_level(): void
    {
        $this->admin();
        $item = $this->item(['quantity_on_hand' => 15, 'reorder_level' => 20]);

        $this->service()->recordTransaction(item: $item, type: 'issue', quantity: 5);
        $this->service()->recordTransaction(item: $item, type: 'issue', quantity: 5);

        $this->assertSame(0, \Illuminate\Notifications\DatabaseNotification::count());
    }

    public function test_alert_does_not_fire_at_exact_reorder_level(): void
    {
        $this->admin();
        $item = $this->item(['quantity_on_hand' => 25, 'reorder_level' => 20]);

        $this->service()->recordTransaction(item: $item, type: 'issue', quantity: 5);

        $this->assertSame('20.000', $item->fresh()->quantity_on_hand);
        $this->assertSame(0, \Illuminate\Notifications\DatabaseNotification::count());
    }

    public function test_restocking_above_threshold_resets_alert_state(): void
    {
        $this->admin();
        $item = $this->item(['quantity_on_hand' => 25, 'reorder_level' => 20]);

        $this->service()->recordTransaction(item: $item, type: 'issue', quantity: 10);
        $this->assertSame(1, \Illuminate\Notifications\DatabaseNotification::count());

        $this->service()->recordTransaction(item: $item, type: 'receipt', quantity: 100);
        $this->assertNull($item->fresh()->low_stock_alerted_at);

        // Re-crossing below fires again.
        $this->service()->recordTransaction(item: $item, type: 'issue', quantity: 110);
        $this->assertSame(2, \Illuminate\Notifications\DatabaseNotification::count());
    }

    public function test_low_stock_alert_is_noop_without_admin_users(): void
    {
        $item = $this->item(['quantity_on_hand' => 25, 'reorder_level' => 20]);

        $this->service()->recordTransaction(item: $item, type: 'issue', quantity: 10);

        $this->assertSame(0, \Illuminate\Notifications\DatabaseNotification::count());
    }

    public function test_alert_can_be_suppressed_per_transaction(): void
    {
        $this->admin();
        $item = $this->item(['quantity_on_hand' => 25, 'reorder_level' => 20]);

        $this->service()->recordTransaction(item: $item, type: 'issue', quantity: 10, alert: false);

        $this->assertSame(0, \Illuminate\Notifications\DatabaseNotification::count());
    }

    public function test_is_low_stock_false_at_exact_reorder_level(): void
    {
        $this->assertFalse($this->item(['quantity_on_hand' => 20, 'reorder_level' => 20])->isLowStock());
        $this->assertTrue($this->item(['quantity_on_hand' => 19.999, 'reorder_level' => 20])->isLowStock());
    }

    public function test_reconcile_quantities_derives_from_ledger(): void
    {
        $item = $this->item(['quantity_on_hand' => 100]);
        $this->service()->recordTransaction(item: $item, type: 'receipt', quantity: 10, alert: false);
        $this->service()->recordTransaction(item: $item, type: 'issue', quantity: 4, alert: false);
        $this->service()->recordTransaction(item: $item, type: 'adjustment', quantity: -1, reason: 'count', alert: false);

        // Drift: the column diverges from the ledger (e.g. a manual DB edit).
        $item->update(['quantity_on_hand' => 999]);

        // Recompute without fixing — the drifted column stays 999.
        $computed = $this->service()->reconcileQuantities();
        $this->assertSame(5.0, $computed[$item->id]);
        $this->assertSame('999.000', $item->fresh()->quantity_on_hand);

        // Fix persists the ledger-derived value.
        $this->service()->reconcileQuantities(fix: true);
        $this->assertSame('5.000', $item->fresh()->quantity_on_hand);
    }

    public function test_low_stock_items_query_returns_only_items_below_reorder(): void
    {
        $low = $this->item(['quantity_on_hand' => 3, 'reorder_level' => 10]);
        $ok = $this->item(['quantity_on_hand' => 10, 'reorder_level' => 10]);
        $healthy = $this->item(['quantity_on_hand' => 50, 'reorder_level' => 10]);

        $result = $this->service()->lowStockItems();

        $this->assertTrue($result->pluck('id')->contains($low->id));
        $this->assertFalse($result->pluck('id')->contains($ok->id));
        $this->assertFalse($result->pluck('id')->contains($healthy->id));
    }

    public function test_status_is_three_state(): void
    {
        $this->assertSame('no_stock', $this->item(['quantity_on_hand' => 0, 'reorder_level' => 10])->status());
        $this->assertSame('no_stock', $this->item(['quantity_on_hand' => 0, 'reorder_level' => 0])->status());
        $this->assertSame('low_stock', $this->item(['quantity_on_hand' => 5, 'reorder_level' => 10])->status());
        $this->assertSame('ok', $this->item(['quantity_on_hand' => 10, 'reorder_level' => 10])->status());
        $this->assertSame('ok', $this->item(['quantity_on_hand' => 20, 'reorder_level' => 10])->status());
    }

    public function test_notify_low_stock_summary_sends_one_aggregate_notification(): void
    {
        $this->admin();
        $lowA = $this->item(['name' => 'Gate Valve ½″', 'quantity_on_hand' => 3, 'reorder_level' => 8]);
        $lowB = $this->item(['name' => 'Teflon Tape', 'quantity_on_hand' => 2, 'reorder_level' => 10]);

        $this->assertTrue($this->service()->notifyLowStockSummary());

        $notifications = \Illuminate\Notifications\DatabaseNotification::query()->get();
        $this->assertCount(1, $notifications);
        $this->assertSame('2 items below reorder level', $notifications->first()->data['title']);
        $this->assertStringContainsString('Gate Valve ½″', $notifications->first()->data['body']);
        $this->assertStringContainsString('Teflon Tape', $notifications->first()->data['body']);
    }

    public function test_notify_low_stock_summary_is_noop_when_everything_is_healthy(): void
    {
        $this->admin();
        $this->item(['name' => 'Healthy Item', 'quantity_on_hand' => 50, 'reorder_level' => 10]);

        $this->assertFalse($this->service()->notifyLowStockSummary());
        $this->assertSame(0, \Illuminate\Notifications\DatabaseNotification::query()->count());
    }
}

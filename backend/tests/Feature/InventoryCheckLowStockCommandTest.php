<?php

namespace Tests\Feature;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\TestCase;

class InventoryCheckLowStockCommandTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function item(array $attributes = []): InventoryItem
    {
        return InventoryItem::factory()->create(array_merge([
            'inventory_category_id' => InventoryCategory::factory(),
            'quantity_on_hand' => 100,
            'reorder_level' => 20,
        ], $attributes));
    }

    public function test_notifies_once_with_aggregate_title_when_multiple_items_are_low(): void
    {
        $this->admin();
        $lowA = $this->item(['name' => 'Gate Valve ½″', 'quantity_on_hand' => 3, 'reorder_level' => 8]);
        $lowB = $this->item(['name' => 'Teflon Tape', 'quantity_on_hand' => 2, 'reorder_level' => 10]);

        $this->artisan('inventory:check-low-stock')
            ->expectsOutputToContain('2 item(s) below reorder level')
            ->assertSuccessful();

        $notifications = DatabaseNotification::query()->get();
        $this->assertCount(1, $notifications);
        $this->assertSame('2 items below reorder level', $notifications->first()->data['title']);
        $this->assertStringContainsString('Gate Valve ½″', $notifications->first()->data['body']);
        $this->assertStringContainsString('Teflon Tape', $notifications->first()->data['body']);
    }

    public function test_uses_singular_title_for_a_single_low_item(): void
    {
        $this->admin();
        $this->item(['name' => 'Water Meter ½″', 'quantity_on_hand' => 4, 'reorder_level' => 5]);

        $this->artisan('inventory:check-low-stock')->assertSuccessful();

        $notification = DatabaseNotification::query()->firstOrFail();
        $this->assertSame('1 item below reorder level', $notification->data['title']);
        $this->assertStringContainsString('/admin/inventory-items', $notification->data['actions'][0]['url'] ?? '');
    }

    public function test_succeeds_without_notifying_when_nothing_is_low(): void
    {
        $this->admin();
        $this->item(['name' => 'Healthy Item', 'quantity_on_hand' => 50, 'reorder_level' => 10]);

        $this->artisan('inventory:check-low-stock')
            ->expectsOutputToContain('no items below reorder level')
            ->assertSuccessful();

        $this->assertSame(0, DatabaseNotification::query()->count());
    }

    public function test_dry_run_reports_without_notifying(): void
    {
        $this->admin();
        $this->item(['name' => 'Low Item', 'quantity_on_hand' => 1, 'reorder_level' => 5]);

        $this->artisan('inventory:check-low-stock', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(0, DatabaseNotification::query()->count());
    }

    public function test_seeding_emits_aggregate_low_stock_notification(): void
    {
        $this->admin();

        $this->seed(\Database\Seeders\InventoryItemSeeder::class);

        $notification = DatabaseNotification::query()->firstOrFail();
        $this->assertSame('1 item below reorder level', $notification->data['title']);
        $this->assertStringContainsString('Water Meter', $notification->data['body']);
        $this->assertStringContainsString('/admin/inventory-items', $notification->data['actions'][0]['url'] ?? '');
    }

    public function test_fix_flag_recomputes_quantities_from_the_ledger_before_checking(): void
    {
        $this->admin();
        $item = $this->item(['name' => 'Drifted Item', 'quantity_on_hand' => 999, 'reorder_level' => 10]);
        // Ledger: opening receipt 100, issue 90 → true stock 10. Simulate a drifted column.
        app(InventoryService::class)->recordTransaction(item: $item, type: 'receipt', quantity: 100, alert: false);
        app(InventoryService::class)->recordTransaction(item: $item, type: 'issue', quantity: 90, alert: false);
        $item->update(['quantity_on_hand' => 999]);

        $this->artisan('inventory:check-low-stock', ['--fix' => true])
            ->expectsOutputToContain('Recomputed quantities')
            ->assertSuccessful();

        $this->assertSame('10.000', $item->fresh()->quantity_on_hand);
    }
}

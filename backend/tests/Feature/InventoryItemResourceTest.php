<?php

namespace Tests\Feature;

use App\Filament\Resources\InventoryItemResource;
use App\Filament\Resources\InventoryItemResource\Pages\CreateInventoryItem;
use App\Filament\Resources\InventoryItemResource\Pages\ListInventoryItems;
use App\Filament\Resources\InventoryItemResource\Pages\ViewInventoryItem;
use App\Filament\Resources\InventoryItemResource\RelationManagers\InventoryTransactionsRelationManager;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InventoryItemResourceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function category(): InventoryCategory
    {
        return InventoryCategory::firstOrCreate(['name' => 'Pipes'], ['name' => 'Pipes']);
    }

    private function item(array $attributes = []): InventoryItem
    {
        return InventoryItem::factory()->create(array_merge([
            'inventory_category_id' => $this->category()->id,
        ], $attributes));
    }

    public function test_list_renders_items_with_status_badge(): void
    {
        $low = $this->item(['name' => 'Low Item', 'quantity_on_hand' => 3, 'reorder_level' => 10]);
        $ok = $this->item(['name' => 'OK Item', 'quantity_on_hand' => 20, 'reorder_level' => 10]);
        $gone = $this->item(['name' => 'Gone Item', 'quantity_on_hand' => 0, 'reorder_level' => 10]);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(ListInventoryItems::class)
            ->assertCanSeeTableRecords([$low, $ok, $gone])
            ->assertSee('Low Item')
            ->assertSee('Low stock')
            ->assertSee('OK Item')
            ->assertSee('OK')
            ->assertSee('Gone Item')
            ->assertSee('No stock');
    }

    public function test_create_requires_reorder_level(): void
    {
        Livewire::actingAs($this->admin(), 'admin')
            ->test(CreateInventoryItem::class)
            ->fillForm([
                'name' => 'Item Without Threshold',
                'inventory_category_id' => $this->category()->id,
                'unit' => 'pc',
                'initial_quantity' => '0',
            ])
            ->call('create')
            ->assertHasFormErrors(['reorder_level' => 'required']);

        $this->assertDatabaseCount('inventory_items', 0);
    }

    public function test_initial_quantity_beyond_three_decimals_is_rejected_without_orphan_item(): void
    {
        Livewire::actingAs($this->admin(), 'admin')
            ->test(CreateInventoryItem::class)
            ->fillForm([
                'name' => 'Precision Item',
                'inventory_category_id' => $this->category()->id,
                'unit' => 'pc',
                'reorder_level' => '5',
                'initial_quantity' => '1.2345',
            ])
            ->call('create')
            ->assertHasFormErrors(['initial_quantity']);

        $this->assertDatabaseCount('inventory_items', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_create_requires_name_and_category(): void
    {
        Livewire::actingAs($this->admin(), 'admin')
            ->test(CreateInventoryItem::class)
            ->fillForm([
                'name' => '',
                'inventory_category_id' => null,
                'unit' => 'pc',
                'reorder_level' => '10',
            ])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required', 'inventory_category_id' => 'required']);
    }

    public function test_create_with_initial_quantity_records_an_opening_receipt(): void
    {
        $category = $this->category();
        $admin = $this->admin();

        Livewire::actingAs($admin, 'admin')
            ->test(CreateInventoryItem::class)
            ->fillForm([
                'name' => 'Lion PVC Pipe 40mm × 40mm — ₱3,240',
                'inventory_category_id' => $category->id,
                'unit' => 'm',
                'reorder_level' => '30',
                'initial_quantity' => '150',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $item = InventoryItem::where('name', 'Lion PVC Pipe 40mm × 40mm — ₱3,240')->firstOrFail();
        $this->assertSame('150.000', $item->quantity_on_hand);
        $this->assertDatabaseHas('inventory_transactions', [
            'inventory_item_id' => $item->id,
            'type' => 'receipt',
            'quantity' => 150,
            'reference' => 'Opening stock',
            'recorded_by' => $admin->id,
        ]);
    }

    public function test_create_with_zero_initial_quantity_writes_no_ledger_row(): void
    {
        Livewire::actingAs($this->admin(), 'admin')
            ->test(CreateInventoryItem::class)
            ->fillForm([
                'name' => 'Bare Item',
                'inventory_category_id' => $this->category()->id,
                'unit' => 'pc',
                'reorder_level' => '0',
                'initial_quantity' => '0',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $item = InventoryItem::where('name', 'Bare Item')->firstOrFail();
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_duplicate_item_name_is_rejected_case_insensitively(): void
    {
        $this->item(['name' => 'Gate Valve ½″']);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(CreateInventoryItem::class)
            ->fillForm([
                'name' => 'GATE VALVE ½″',
                'inventory_category_id' => $this->category()->id,
                'unit' => 'pc',
                'reorder_level' => '5',
            ])
            ->call('create')
            ->assertHasFormErrors(['name']);

        $this->assertDatabaseCount('inventory_items', 1);
    }

    public function test_delete_is_blocked_for_an_item_with_transactions(): void
    {
        $item = $this->item();
        InventoryTransaction::factory()->receipt()->create(['inventory_item_id' => $item->id]);

        $this->assertFalse(InventoryItemResource::canDelete($item));
    }

    public function test_delete_is_allowed_for_an_item_without_transactions(): void
    {
        $this->assertTrue(InventoryItemResource::canDelete($this->item()));
    }

    public function test_add_stock_action_records_receipt_and_updates_quantity(): void
    {
        $admin = $this->admin();
        $item = $this->item(['quantity_on_hand' => 10]);

        Livewire::actingAs($admin, 'admin')
            ->test(ViewInventoryItem::class, ['record' => $item->id])
            ->callAction('addStock', data: [
                'quantity' => '20',
                'reference' => 'PO #99',
                'moved_at' => now()->toDateString(),
            ])
            ->assertNotified();

        $this->assertSame('30.000', $item->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('inventory_transactions', [
            'inventory_item_id' => $item->id,
            'type' => 'receipt',
            'quantity' => 20,
            'reference' => 'PO #99',
            'recorded_by' => $admin->id,
        ]);
    }

    public function test_remove_stock_action_records_issue(): void
    {
        $admin = $this->admin();
        $item = $this->item(['quantity_on_hand' => 10]);

        Livewire::actingAs($admin, 'admin')
            ->test(ViewInventoryItem::class, ['record' => $item->id])
            ->callAction('removeStock', data: [
                'quantity' => '4',
                'reason' => 'Repair at Purok 3',
                'reference' => 'Work order #2',
                'moved_at' => now()->toDateString(),
            ])
            ->assertNotified();

        $this->assertSame('6.000', $item->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('inventory_transactions', [
            'inventory_item_id' => $item->id,
            'type' => 'issue',
            'quantity' => 4,
            'reason' => 'Repair at Purok 3',
            'recorded_by' => $admin->id,
        ]);
    }

    public function test_remove_stock_to_exact_zero_is_allowed(): void
    {
        $admin = $this->admin();
        $item = $this->item(['quantity_on_hand' => 2]);

        Livewire::actingAs($admin, 'admin')
            ->test(ViewInventoryItem::class, ['record' => $item->id])
            ->callAction('removeStock', data: [
                'quantity' => '2',
                'moved_at' => now()->toDateString(),
            ])
            ->assertNotified();

        $this->assertSame('0.000', $item->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('inventory_transactions', [
            'inventory_item_id' => $item->id,
            'type' => 'issue',
            'quantity' => 2,
            'recorded_by' => $admin->id,
        ]);
    }

    public function test_remove_stock_overdraw_keeps_quantity_unchanged(): void
    {
        $item = $this->item(['quantity_on_hand' => 3]);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(ViewInventoryItem::class, ['record' => $item->id])
            ->callAction('removeStock', data: [
                'quantity' => '10',
                'moved_at' => now()->toDateString(),
            ])
            ->assertNotified(); // danger notification

        $this->assertSame('3.000', $item->fresh()->quantity_on_hand);
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_low_stock_alert_fires_from_the_add_stock_flow_crossing_down(): void
    {
        $admin = $this->admin();
        $item = $this->item(['quantity_on_hand' => 20, 'reorder_level' => 10]);

        Livewire::actingAs($admin, 'admin')
            ->test(ViewInventoryItem::class, ['record' => $item->id])
            ->callAction('removeStock', data: [
                'quantity' => '15',
                'moved_at' => now()->toDateString(),
            ]);

        $this->assertSame(1, \Illuminate\Notifications\DatabaseNotification::query()->count());
    }

    public function test_transactions_relation_manager_lists_the_ledger(): void
    {
        $item = $this->item();
        InventoryTransaction::factory()->receipt()->create([
            'inventory_item_id' => $item->id,
            'quantity' => 50,
            'reference' => 'PO #1',
        ]);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(InventoryTransactionsRelationManager::class, [
                'ownerRecord' => $item,
                'pageClass' => ViewInventoryItem::class,
            ])
            ->assertSee('Receipt')
            ->assertSee('PO #1');
    }

    public function test_transactions_relation_manager_create_stamps_recorded_by(): void
    {
        $admin = $this->admin();
        $item = $this->item(['quantity_on_hand' => 10]);

        Livewire::actingAs($admin, 'admin')
            ->test(InventoryTransactionsRelationManager::class, [
                'ownerRecord' => $item,
                'pageClass' => ViewInventoryItem::class,
            ])
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'type' => 'issue',
                'quantity' => '6',
                'reference' => 'Work order #5',
                'moved_at' => now()->toDateString(),
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('4.000', $item->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('inventory_transactions', [
            'inventory_item_id' => $item->id,
            'type' => 'issue',
            'quantity' => 6,
            'reference' => 'Work order #5',
            'recorded_by' => $admin->id,
        ]);
    }

    public function test_transactions_relation_manager_issue_to_exact_zero_is_allowed(): void
    {
        $item = $this->item(['quantity_on_hand' => 2]);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(InventoryTransactionsRelationManager::class, [
                'ownerRecord' => $item,
                'pageClass' => ViewInventoryItem::class,
            ])
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'type' => 'issue',
                'quantity' => '2',
                'moved_at' => now()->toDateString(),
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('0.000', $item->fresh()->quantity_on_hand);
    }

    public function test_transactions_relation_manager_refreshes_owner_record_after_create(): void
    {
        $item = $this->item(['quantity_on_hand' => 2]);

        $component = Livewire::actingAs($this->admin(), 'admin')
            ->test(InventoryTransactionsRelationManager::class, [
                'ownerRecord' => $item,
                'pageClass' => ViewInventoryItem::class,
            ]);

        $component
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'type' => 'issue',
                'quantity' => '1',
                'moved_at' => now()->toDateString(),
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('1.000', $component->instance()->getOwnerRecord()->quantity_on_hand);
    }

    public function test_transactions_relation_manager_adjustment_requires_reason(): void
    {
        $item = $this->item(['quantity_on_hand' => 10]);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(InventoryTransactionsRelationManager::class, [
                'ownerRecord' => $item,
                'pageClass' => ViewInventoryItem::class,
            ])
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'type' => 'adjustment',
                'quantity' => '5',
                'moved_at' => now()->toDateString(),
            ])
            ->assertHasActionErrors(['reason' => 'required']);

        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_transactions_relation_manager_overdraw_surfaces_field_error(): void
    {
        $item = $this->item(['quantity_on_hand' => 2]);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(InventoryTransactionsRelationManager::class, [
                'ownerRecord' => $item,
                'pageClass' => ViewInventoryItem::class,
            ])
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'type' => 'issue',
                'quantity' => '50',
                'moved_at' => now()->toDateString(),
            ])
            ->assertHasActionErrors(['quantity']);

        $this->assertSame('2.000', $item->fresh()->quantity_on_hand);
    }
}

<?php

namespace Tests\Feature;

use App\Filament\Resources\InventoryCategoryResource\Pages\CreateInventoryCategory;
use App\Filament\Resources\InventoryCategoryResource\Pages\EditInventoryCategory;
use App\Filament\Resources\InventoryCategoryResource\Pages\ListInventoryCategories;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InventoryCategoryResourceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_list_renders_categories_with_item_counts(): void
    {
        $category = InventoryCategory::factory()->create(['name' => 'Pipes']);
        InventoryItem::factory()->count(2)->create(['inventory_category_id' => $category->id]);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(ListInventoryCategories::class)
            ->assertCanSeeTableRecords([$category])
            ->assertSee('Pipes')
            ->assertSee('2');
    }

    public function test_create_category_works(): void
    {
        Livewire::actingAs($this->admin(), 'admin')
            ->test(CreateInventoryCategory::class)
            ->fillForm(['name' => 'Chemicals'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('inventory_categories', ['name' => 'Chemicals']);
    }

    public function test_duplicate_category_name_is_rejected_case_insensitively(): void
    {
        InventoryCategory::factory()->create(['name' => 'Pipes']);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(CreateInventoryCategory::class)
            ->fillForm(['name' => 'pipes'])
            ->call('create')
            ->assertHasFormErrors(['name']);

        $this->assertDatabaseCount('inventory_categories', 1);
    }

    public function test_edit_rename_keeps_unique_guard_and_allows_own_name(): void
    {
        $category = InventoryCategory::factory()->create(['name' => 'Pipes']);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(EditInventoryCategory::class, ['record' => $category->id])
            ->fillForm(['name' => 'Pipes'])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_is_name_taken_helper_is_case_insensitive_and_respects_except_id(): void
    {
        $category = InventoryCategory::factory()->create(['name' => 'Pipes']);

        $this->assertTrue(InventoryCategory::isNameTaken('PIPES'));
        $this->assertTrue(InventoryCategory::isNameTaken('pipes'));
        $this->assertFalse(InventoryCategory::isNameTaken('Pipes', $category->id));
        $this->assertFalse(InventoryCategory::isNameTaken('Valves'));
    }

    public function test_delete_is_blocked_while_items_reference_the_category(): void
    {
        $category = InventoryCategory::factory()->create(['name' => 'Pipes']);
        InventoryItem::factory()->create(['inventory_category_id' => $category->id]);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(ListInventoryCategories::class)
            ->callTableAction(DeleteAction::class, $category)
            ->assertNotified('Category in use');

        $this->assertDatabaseHas('inventory_categories', ['id' => $category->id]);
    }

    public function test_delete_is_allowed_for_an_unused_category(): void
    {
        $category = InventoryCategory::factory()->create(['name' => 'Valves']);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(ListInventoryCategories::class)
            ->callTableAction(DeleteAction::class, $category);

        $this->assertDatabaseMissing('inventory_categories', ['id' => $category->id]);
    }
}

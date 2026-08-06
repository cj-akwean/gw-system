<?php

namespace Tests\Feature;

use App\Filament\Resources\ServiceConnectionResource;
use App\Filament\Resources\ServiceConnectionResource\Pages\EditServiceConnection;
use App\Filament\Resources\ServiceConnectionResource\Pages\ListServiceConnections;
use App\Filament\Resources\ServiceConnectionResource\Pages\ViewServiceConnection;
use App\Jobs\SendConnectionIdentifierChangedEmail;
use App\Models\Barangay;
use App\Models\ConnectionLink;
use App\Models\Invoice;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ServiceConnectionResourceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function pinInvoice(
        ServiceConnection $connection,
        string $status,
        float $total,
    ): Invoice {
        return Invoice::factory()->create([
            'service_connection_id' => $connection->id,
            'status' => $status,
            'total_amount' => $total,
            'previous_balance' => $status === 'overdue' ? $total : 0,
            'base_amount' => $status === 'overdue' ? 0 : $total,
            'penalty_amount' => 0,
        ]);
    }

    public function test_list_renders_connections_with_pending_balance(): void
    {
        $c1 = ServiceConnection::factory()->create(['account_number' => 'GW-00001']);
        $c2 = ServiceConnection::factory()->create(['account_number' => 'GW-00002']);

        $this->pinInvoice($c1, 'unpaid', 150.50);
        $this->pinInvoice($c2, 'overdue', 200.00);
        $this->pinInvoice($c1, 'paid', 9999.00);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(ListServiceConnections::class)
            ->assertCanSeeTableRecords([$c1, $c2])
            ->assertSee('GW-00001')
            ->assertSee('GW-00002')
            ->assertSee('₱150.50')
            ->assertSee('₱200.00')
            ->assertDontSee('₱9999.00');
    }

    public function test_status_filter_deep_link_applies(): void
    {
        $active = ServiceConnection::factory()->create([
            'status' => 'active',
            'account_number' => 'GW-ACTIVE',
        ]);
        $inactive = ServiceConnection::factory()->create([
            'status' => 'inactive',
            'account_number' => 'GW-OFF',
        ]);

        Livewire::withQueryParams([
            'filters' => json_encode(['status' => ['value' => 'active']]),
        ])->actingAs($this->admin(), 'admin')
            ->test(ListServiceConnections::class)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$inactive]);
    }

    public function test_edit_updates_connection_and_redirects_to_view(): void
    {
        $barangay = Barangay::factory()->create();
        $connection = ServiceConnection::factory()->create(['barangay_id' => $barangay->id]);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(EditServiceConnection::class, ['record' => $connection->id])
            ->fillForm([
                'account_number' => 'GW-NEW-001',
                'meter_number' => 'MTR-NEW-001',
                'registered_name' => 'Renamed Customer',
                'barangay_id' => $barangay->id,
                'address' => 'New Address St.',
                'status' => 'active',
                'connection_date' => '2026-01-15',
                'rate_schedule_id' => null,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(ServiceConnectionResource::getUrl('view', ['record' => $connection->id]));

        $this->assertDatabaseHas('service_connections', [
            'id' => $connection->id,
            'account_number' => 'GW-NEW-001',
            'meter_number' => 'MTR-NEW-001',
            'registered_name' => 'Renamed Customer',
        ]);
    }

    public function test_duplicate_identifier_rejected_on_edit_but_own_value_allowed(): void
    {
        $other = ServiceConnection::factory()->create(['account_number' => 'GW-AAA']);
        $mine = ServiceConnection::factory()->create(['account_number' => 'GW-BBB']);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(EditServiceConnection::class, ['record' => $mine->id])
            ->fillForm(['account_number' => 'GW-AAA'])
            ->call('save')
            ->assertHasFormErrors(['account_number']);

        $this->assertSame('GW-BBB', $mine->fresh()->account_number);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(EditServiceConnection::class, ['record' => $mine->id])
            ->fillForm(['account_number' => 'GW-BBB'])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_create_route_never_registered(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/service-connections/create')
            ->assertNotFound();
    }

    public function test_identifier_change_dispatches_email_to_linked_users_with_old_values(): void
    {
        Queue::fake();

        $user = User::factory()->create(['email' => 'CUSTOMER@Example.COM']);
        $connection = ServiceConnection::factory()->create([
            'account_number' => 'GW-OLD-001',
            'meter_number' => 'MTR-OLD-001',
        ]);
        ConnectionLink::factory()->create([
            'user_id' => $user->id,
            'service_connection_id' => $connection->id,
            'status' => 'active',
            'unlinked_at' => null,
        ]);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(EditServiceConnection::class, ['record' => $connection->id])
            ->fillForm(['account_number' => 'GW-NEW-001'])
            ->call('save')
            ->assertHasNoFormErrors();

        Queue::assertPushed(SendConnectionIdentifierChangedEmail::class, function ($job) use ($connection) {
            return $job->serviceConnection->is($connection)
                && $job->oldIdentifiers === ['account_number' => 'GW-OLD-001'];
        });
    }

    public function test_unchanged_edit_does_not_notify(): void
    {
        Queue::fake();

        $connection = ServiceConnection::factory()->create();

        Livewire::actingAs($this->admin(), 'admin')
            ->test(EditServiceConnection::class, ['record' => $connection->id])
            ->fillForm(['registered_name' => 'Same identifiers, new name'])
            ->call('save')
            ->assertHasNoFormErrors();

        Queue::assertNotPushed(SendConnectionIdentifierChangedEmail::class);
    }

    public function test_identifier_change_with_no_linked_users_does_not_notify(): void
    {
        Queue::fake();

        $connection = ServiceConnection::factory()->create(['account_number' => 'GW-00001']);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(EditServiceConnection::class, ['record' => $connection->id])
            ->fillForm(['account_number' => 'GW-00002'])
            ->call('save')
            ->assertHasNoFormErrors();

        Queue::assertNotPushed(SendConnectionIdentifierChangedEmail::class);
    }

    public function test_view_page_shows_connection_details_and_relation_tabs(): void
    {
        $connection = ServiceConnection::factory()->create([
            'account_number' => 'GW-VIEW-1',
            'registered_name' => 'Viewable Customer',
        ]);

        $this->pinInvoice($connection, 'unpaid', 250.00);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(ViewServiceConnection::class, ['record' => $connection->id])
            ->assertSee('View GW-VIEW-1')
            ->assertSee('Meter Readings')
            ->assertSee('Invoices');
    }
}

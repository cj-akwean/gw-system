<?php

namespace Tests\Feature;

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConnectionLinkApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_link_active_connection_via_api(): void
    {
        $user = User::factory()->create();
        $connection = ServiceConnection::factory()->create([
            'status' => 'active',
            'account_number' => 'GW-LINK-A',
            'meter_number' => 'MTR-LINK-A',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/links', [
            'account_number' => $connection->account_number,
            'meter_number' => $connection->meter_number,
        ])->assertCreated();

        $this->assertDatabaseHas('connection_links', [
            'user_id' => $user->id,
            'service_connection_id' => $connection->id,
            'status' => 'active',
        ]);
    }

    public function test_cannot_link_pending_connection_via_api(): void
    {
        $user = User::factory()->create();
        $connection = ServiceConnection::factory()->create([
            'status' => 'pending',
            'account_number' => 'GW-LINK-P',
            'meter_number' => 'MTR-LINK-P',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/links', [
            'account_number' => $connection->account_number,
            'meter_number' => $connection->meter_number,
        ])->assertNotFound();

        $this->assertDatabaseMissing('connection_links', [
            'user_id' => $user->id,
            'service_connection_id' => $connection->id,
        ]);
    }

    public function test_cannot_link_disconnected_connection_via_api(): void
    {
        $user = User::factory()->create();
        $connection = ServiceConnection::factory()->create([
            'status' => 'disconnected',
            'account_number' => 'GW-LINK-D',
            'meter_number' => 'MTR-LINK-D',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/links', [
            'account_number' => $connection->account_number,
            'meter_number' => $connection->meter_number,
        ])->assertNotFound();

        $this->assertDatabaseMissing('connection_links', [
            'user_id' => $user->id,
            'service_connection_id' => $connection->id,
        ]);
    }
}

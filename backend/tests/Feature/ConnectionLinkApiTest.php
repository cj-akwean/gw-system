<?php

namespace Tests\Feature;

use App\Models\ConnectionLink;
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

    public function test_cannot_link_connection_already_linked_to_another_user(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $connection = ServiceConnection::factory()->create([
            'status' => 'active',
            'account_number' => 'GW-LINK-B',
            'meter_number' => 'MTR-LINK-B',
        ]);

        ConnectionLink::create([
            'user_id' => $owner->id,
            'service_connection_id' => $connection->id,
            'status' => 'active',
            'linked_at' => now(),
        ]);

        Sanctum::actingAs($otherUser);

        $this->postJson('/api/links', [
            'account_number' => $connection->account_number,
            'meter_number' => $connection->meter_number,
        ])->assertStatus(409)
            ->assertJson(['message' => 'This meter is already linked to another account.']);

        $this->assertDatabaseMissing('connection_links', [
            'user_id' => $otherUser->id,
            'service_connection_id' => $connection->id,
        ]);
    }

    public function test_linking_own_connection_again_is_idempotent(): void
    {
        $user = User::factory()->create();
        $connection = ServiceConnection::factory()->create([
            'status' => 'active',
            'account_number' => 'GW-LINK-C',
            'meter_number' => 'MTR-LINK-C',
        ]);

        ConnectionLink::create([
            'user_id' => $user->id,
            'service_connection_id' => $connection->id,
            'status' => 'active',
            'linked_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/links', [
            'account_number' => $connection->account_number,
            'meter_number' => $connection->meter_number,
        ])->assertCreated();

        $this->assertEquals(1, ConnectionLink::where('user_id', $user->id)
            ->where('service_connection_id', $connection->id)
            ->count());
    }

    public function test_connection_with_revoked_link_can_be_linked_by_another_user(): void
    {
        $previousOwner = User::factory()->create();
        $newUser = User::factory()->create();
        $connection = ServiceConnection::factory()->create([
            'status' => 'active',
            'account_number' => 'GW-LINK-E',
            'meter_number' => 'MTR-LINK-E',
        ]);

        ConnectionLink::create([
            'user_id' => $previousOwner->id,
            'service_connection_id' => $connection->id,
            'status' => 'revoked',
            'linked_at' => now()->subDay(),
            'unlinked_at' => now(),
        ]);

        Sanctum::actingAs($newUser);

        $this->postJson('/api/links', [
            'account_number' => $connection->account_number,
            'meter_number' => $connection->meter_number,
        ])->assertCreated();

        $this->assertDatabaseHas('connection_links', [
            'user_id' => $newUser->id,
            'service_connection_id' => $connection->id,
            'status' => 'active',
        ]);
    }
}

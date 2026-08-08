<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileUpdateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_profile(): void
    {
        $user = User::factory()->create(['name' => null, 'avatar_id' => null]);

        Sanctum::actingAs($user);

        $this->patchJson('/api/profile', [
            'name' => 'AquaFan',
            'avatar_id' => 3,
        ])->assertOk()
            ->assertJson([
                'id' => $user->id,
                'name' => 'AquaFan',
                'email' => $user->email,
                'avatar_id' => 3,
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'AquaFan',
            'avatar_id' => 3,
        ]);
    }

    public function test_profile_update_rejects_short_name(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/profile', [
            'name' => 'ab',
            'avatar_id' => 1,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_profile_update_rejects_name_longer_than_20(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/profile', [
            'name' => str_repeat('x', 21),
            'avatar_id' => 1,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_profile_update_rejects_unknown_avatar_id(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/profile', [
            'name' => 'ValidName',
            'avatar_id' => 5,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['avatar_id']);
    }

    public function test_profile_update_requires_authentication(): void
    {
        $this->patchJson('/api/profile', [
            'name' => 'ValidName',
            'avatar_id' => 1,
        ])->assertStatus(401);
    }
}

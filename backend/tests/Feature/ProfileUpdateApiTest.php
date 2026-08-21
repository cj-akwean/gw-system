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

    public function test_profile_update_rejects_name_longer_than_50(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/profile', [
            'name' => str_repeat('x', 51),
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

    public function test_user_can_save_and_retrieve_a_phone(): void
    {
        $user = User::factory()->create([
            'name' => null,
            'avatar_id' => null,
            'phone' => null,
        ]);

        Sanctum::actingAs($user);

        $this->patchJson('/api/profile', [
            'name' => 'AquaFan',
            'avatar_id' => 2,
            'phone' => '09171234567',
        ])->assertOk()
            ->assertJson([
                'id' => $user->id,
                'name' => 'AquaFan',
                'email' => $user->email,
                'avatar_id' => 2,
                'phone' => '09171234567',
            ]);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'phone' => '09171234567']);
    }

    public function test_international_prefixed_phone_is_accepted(): void
    {
        $user = User::factory()->create(['phone' => null]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/profile', [
            'name' => 'ValidName',
            'avatar_id' => 1,
            'phone' => '+639171234567',
        ])->assertOk()
            ->assertJson(['phone' => '+639171234567']);
    }

    public function test_phone_can_be_cleared_to_null(): void
    {
        $user = User::factory()->create([
            'name' => 'ValidName',
            'avatar_id' => 1,
            'phone' => '09171234567',
        ]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/profile', [
            'name' => 'ValidName',
            'avatar_id' => 1,
            'phone' => null,
        ])->assertOk()
            ->assertJson(['phone' => null]);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'phone' => null]);
    }

    public function test_invalid_phone_is_rejected(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/profile', [
            'name' => 'ValidName',
            'avatar_id' => 1,
            'phone' => '091717',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }
}

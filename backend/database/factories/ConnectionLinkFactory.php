<?php

namespace Database\Factories;

use App\Models\ConnectionLink;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConnectionLinkFactory extends Factory
{
    protected $model = ConnectionLink::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'service_connection_id' => ServiceConnection::factory(),
            'status' => 'active',
            'linked_at' => now(),
            'unlinked_at' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'revoked',
            'unlinked_at' => now()->subDays(rand(1, 30)),
        ]);
    }
}

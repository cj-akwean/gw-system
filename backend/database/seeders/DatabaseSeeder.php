<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(BarangaySeeder::class);
        $this->call(PenaltyRuleSeeder::class);
        $this->call(RateScheduleSeeder::class);
        $this->call(ServiceConnectionSeeder::class);

        User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@gwsystem.com',
            'password' => bcrypt('admin123'),
            'is_admin' => true,
        ]);

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'is_admin' => false,
        ]);
    }
}

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

        User::firstOrCreate([
            'email' => 'admin@gwsystem.com',
        ], [
            'name' => 'Admin',
            'password' => bcrypt('admin123'),
            'is_admin' => true,
        ]);

        User::firstOrCreate([
            'email' => 'test@example.com',
        ], [
            'name' => 'Test User',
            'is_admin' => false,
        ]);
    }
}

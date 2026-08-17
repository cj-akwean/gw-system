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

        User::updateOrCreate([
            'email' => 'admin@gwsystem.com',
        ], [
            'name' => 'Admin',
            'password' => bcrypt('admin123'),
            'is_admin' => true,
        ]);

        User::updateOrCreate([
            'email' => 'test@example.com',
        ], [
            'name' => 'Test User',
            'password' => bcrypt('password'),
            'is_admin' => false,
        ]);

        $this->call(DemoPortalDataSeeder::class);
        $this->call(InventoryItemSeeder::class);
    }
}

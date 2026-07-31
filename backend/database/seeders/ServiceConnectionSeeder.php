<?php

namespace Database\Seeders;

use App\Models\ServiceConnection;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ServiceConnectionSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        ServiceConnection::factory()->count(15)->create();
    }
}

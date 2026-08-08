<?php

namespace App\Console\Commands;

use Database\Seeders\BarangaySeeder;
use Database\Seeders\DemoPortalDataSeeder;
use Database\Seeders\PenaltyRuleSeeder;
use Database\Seeders\RateScheduleSeeder;
use Database\Seeders\ServiceConnectionSeeder;
use Database\Seeders\TestPaymentSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class SeedTestDataCommand extends Command
{
    protected $signature = 'test:seed-data {--fresh : Run migrate:fresh before seeding (wipes everything)}';

    protected $description = 'Reset billing data and seed test payments for all 15 connections (April–August)';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            $this->info('Running migrate:fresh (full reset)…');

            Artisan::call('migrate:fresh', ['--force' => true]);
            $this->info(Artisan::output());

            $this->info('Seeding base data…');
            DB::transaction(function () {
                app(BarangaySeeder::class)->run();
                app(PenaltyRuleSeeder::class)->run();
                app(RateScheduleSeeder::class)->run();
                app(ServiceConnectionSeeder::class)->run();
            });

            User::updateOrCreate(
                ['email' => 'admin@gwsystem.com'],
                ['name' => 'Admin', 'password' => Hash::make('admin123'), 'is_admin' => true],
            );

            User::updateOrCreate(
                ['email' => 'test@example.com'],
                ['name' => 'Test User', 'password' => Hash::make('password'), 'is_admin' => false],
            );

            $this->info('Base data seeded.');
            $this->newLine();
        }

        $this->info('Running TestPaymentSeeder…');

        $seeder = new TestPaymentSeeder();
        $seeder->setCommand($this);
        $seeder->run();

        $this->newLine();
        $this->info('Done. Test data ready.');
        $this->info('Portal: test@example.com / password');
        $this->info('Admin:  admin@gwsystem.com / admin123');

        return self::SUCCESS;
    }
}

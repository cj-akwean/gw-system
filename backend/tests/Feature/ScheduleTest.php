<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks in the Laravel scheduler wiring (routes/console.php) — the app-side
 * half of the Infra-phase cron. The server only needs a single `* * * * * php
 * artisan schedule:run` line; these registers must exist and be correct before
 * a deploy, because a mis-timed run would fire billing/reconcile at the wrong
 * moment (money-critical).
 */
class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function scheduledEvents(): array
    {
        return $this->app->make(Schedule::class)->events();
    }

    public function test_scheduler_registers_monthly_billing_run(): void
    {
        $events = collect($this->scheduledEvents());

        $billing = $events->first(
            fn (object $event): bool => str_contains((string) $event->command, 'billing:run')
        );

        $this->assertNotNull($billing, 'billing:run must be registered on the scheduler.');
        $this->assertSame('5 3 1 * *', $billing->expression, 'Billing runs on the 1st at 03:05.');
        $this->assertSame('Asia/Manila', $billing->timezone);
        $this->assertTrue($billing->withoutOverlapping, 'A slow/stuck prior billing run must never overlap.');
    }

    public function test_scheduler_registers_daily_paymongo_reconcile(): void
    {
        $events = collect($this->scheduledEvents());

        $reconcile = $events->first(
            fn (object $event): bool => str_contains((string) $event->command, 'paymongo:reconcile')
        );

        $this->assertNotNull($reconcile, 'paymongo:reconcile must be registered on the scheduler.');
        $this->assertSame('0 6 * * *', $reconcile->expression, 'Reconcile runs daily at 06:00.');
        $this->assertSame('Asia/Manila', $reconcile->timezone);
        $this->assertTrue($reconcile->withoutOverlapping);
    }

    public function test_scheduler_registers_daily_inventory_low_stock_check(): void
    {
        $events = collect($this->scheduledEvents());

        $check = $events->first(
            fn (object $event): bool => str_contains((string) $event->command, 'inventory:check-low-stock')
        );

        $this->assertNotNull($check, 'inventory:check-low-stock must be registered on the scheduler.');
        $this->assertSame('0 7 * * *', $check->expression, 'Low-stock check runs daily at 07:00.');
        $this->assertSame('Asia/Manila', $check->timezone);
        $this->assertTrue($check->withoutOverlapping);
    }

    public function test_schedule_list_shows_all_entries(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('billing:run')
            ->expectsOutputToContain('paymongo:reconcile')
            ->expectsOutputToContain('inventory:check-low-stock')
            ->assertSuccessful();
    }
}

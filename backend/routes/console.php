<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Monthly billing run — the 1st at 03:05 (Asia/Manila), queued by default.
 * The explicit --period pins the computation to the app timezone: "last day of
 * the previous month". The worker (supervisor / Windows task) must be running;
 * without it the queued RunBillingJob waits in `jobs`. withoutOverlapping()
 * guards a slow/stuck prior run. If the office routinely imports month-end
 * readings ON the 1st, move the hour/date here (single edit point).
 */
Schedule::command('billing:run', [
    '--period' => Carbon::now('Asia/Manila')->startOfMonth()->subDay()->toDateString(),
])
    ->monthlyOn(1, '03:05')
    ->timezone('Asia/Manila')
    ->withoutOverlapping();

/**
 * Daily read-only PayMongo reconciliation safety net (never mutates state).
 * Reports discrepancies to the `paymongo` log channel; exit code 1 when it
 * finds any — the scheduler records the failure, the 'paymongo' log is the
 * audit trail.
 */
Schedule::command('paymongo:reconcile')
    ->dailyAt('06:00')
    ->timezone('Asia/Manila')
    ->withoutOverlapping();

/**
 * Auto-credit invoices whose PayMongo intent succeeded but the webhook was
 * missed (wrong URL, ngrok down, etc.). Runs every 5 minutes as a
 * background safety net — credits the invoice, sends a notification, and
 * logs the action to the paymongo channel.
 */
Schedule::command('paymongo:auto-credit')
    ->everyFiveMinutes()
    ->withoutOverlapping();

/**
 * Daily low-stock inventory digest — one aggregate admin notification for
 * every item below its reorder level (safety net behind the immediate
 * boundary-crossing alerts in InventoryService). Runs at 07:00 PH before
 * the office opens. --fix recomputes quantities from the ledger.
 */
Schedule::command('inventory:check-low-stock')
    ->dailyAt('07:00')
    ->timezone('Asia/Manila')
    ->withoutOverlapping();

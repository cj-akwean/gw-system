<?php

namespace Database\Seeders;

use App\Models\ConnectionLink;
use App\Models\Invoice;
use App\Models\MeterReading;
use App\Models\Payment;
use App\Models\RateSchedule;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Database\Seeder;

class TestPaymentSeeder extends Seeder
{
    /*
    |--------------------------------------------------------------------------
    | Usage profiles — each connection gets assigned one to simulate
    | different real-world consumption patterns.
    |
    | 'steady_*' profiles use a fixed usage value per month.
    | 'erratic' has wild month-to-month swings.
    | 'increasing' simulates gradual ramp-up (new tenant, filling up, etc.).
    |
    | The array index maps to the billing period index (0 = April … 4 = August).
    | Units: cubic meters (cu.m.) — multiplied by ₱10.00 flat rate for the bill.
    */
    private const PROFILES = [
        'steady_low'  => [20, 20, 20, 20, 20],
        'steady_med'  => [35, 35, 35, 35, 35],
        'steady_high' => [65, 65, 65, 65, 65],
        'erratic'     => [10, 60, 15, 50, 20],
        'increasing'  => [15, 25, 35, 45, 55],
    ];

    private const BILLING_PERIODS = [
        ['start' => '2026-04-01', 'end' => '2026-04-30', 'reading_date' => '2026-04-28 10:00:00'],
        ['start' => '2026-05-01', 'end' => '2026-05-31', 'reading_date' => '2026-05-28 10:00:00'],
        ['start' => '2026-06-01', 'end' => '2026-06-30', 'reading_date' => '2026-06-28 10:00:00'],
        ['start' => '2026-07-01', 'end' => '2026-07-31', 'reading_date' => '2026-07-28 10:00:00'],
        ['start' => '2026-08-01', 'end' => '2026-08-05', 'reading_date' => '2026-08-05 10:00:00'],
    ];

    /**
     | Status distribution across 15 connections (each has 5 invoices):
     |
     | Connections  1–5:  all 5 months PAID     → clean accounts, test receipts
     | Connections  6–10: months 1–3 paid, 4–5 OVERDUE  → penalty accumulation
     | Connections 11–15: months 1–2 paid, 3 overdue, 4–5 UNPAID → realistic mix
     */
    private const STATUS_MAP = [
        1  => ['paid', 'paid', 'paid', 'paid', 'paid'],
        2  => ['paid', 'paid', 'paid', 'paid', 'paid'],
        3  => ['paid', 'paid', 'paid', 'paid', 'paid'],
        4  => ['paid', 'paid', 'paid', 'paid', 'paid'],
        5  => ['paid', 'paid', 'paid', 'paid', 'paid'],
        6  => ['paid', 'paid', 'overdue', 'overdue', 'overdue'],
        7  => ['paid', 'paid', 'overdue', 'overdue', 'overdue'],
        8  => ['paid', 'paid', 'overdue', 'overdue', 'overdue'],
        9  => ['paid', 'paid', 'overdue', 'overdue', 'overdue'],
        10 => ['paid', 'paid', 'overdue', 'overdue', 'overdue'],
        11 => ['paid', 'overdue', 'unpaid', 'unpaid', 'unpaid'],
        12 => ['paid', 'overdue', 'unpaid', 'unpaid', 'unpaid'],
        13 => ['paid', 'overdue', 'unpaid', 'unpaid', 'unpaid'],
        14 => ['overdue', 'unpaid', 'unpaid', 'unpaid', 'unpaid'],
        15 => ['overdue', 'unpaid', 'unpaid', 'unpaid', 'unpaid'],
    ];

    private const USAGE_PROFILES_ORDER = [
        'steady_low',
        'steady_low',
        'steady_low',
        'steady_med',
        'steady_med',
        'steady_med',
        'steady_high',
        'steady_high',
        'steady_high',
        'erratic',
        'erratic',
        'erratic',
        'increasing',
        'increasing',
        'increasing',
    ];

    public function run(): void
    {
        $this->command->info('Wiping billing data…');

        Payment::query()->delete();
        Invoice::query()->delete();
        MeterReading::query()->delete();
        ConnectionLink::query()->delete();

        $connections = ServiceConnection::query()->where('status', 'active')->orderBy('id')->get();
        $testUser = User::query()->where('email', 'test@example.com')->first();
        $admin = User::query()->where('is_admin', true)->first();

        if (! $testUser || ! $admin) {
            $this->command->error('Missing test@example.com or admin user. Run DatabaseSeeder first.');

            return;
        }

        $rateSchedule = RateSchedule::query()->orderBy('effective_from')->first();

        if (! $rateSchedule) {
            $this->command->error('No rate schedule found. Run RateScheduleSeeder first.');

            return;
        }

        $this->command->info("Linking {$connections->count()} connections to {$testUser->email}…");

        foreach ($connections as $connection) {
            ConnectionLink::create([
                'user_id' => $testUser->id,
                'service_connection_id' => $connection->id,
                'status' => 'active',
                'linked_at' => now()->subMonths(6),
            ]);
        }

        $billingService = new BillingService();
        $graceDays = 15;

        $this->command->info('Creating meter readings and invoices…');

        foreach ($connections as $connectionIdx => $connection) {
            $profileName = self::USAGE_PROFILES_ORDER[$connectionIdx];
            $usage = self::PROFILES[$profileName];
            $statuses = self::STATUS_MAP[$connectionIdx + 1];
            $previousReading = 100.00;

            foreach (self::BILLING_PERIODS as $periodIdx => $period) {
                $cuMUsed = (float) $usage[$periodIdx];
                $presentReading = $previousReading + $cuMUsed;

                $reading = MeterReading::create([
                    'service_connection_id' => $connection->id,
                    'present_reading' => $presentReading,
                    'previous_reading' => $previousReading,
                    'cu_m_used' => $cuMUsed,
                    'entered_by' => $admin->id,
                    'entered_at' => $period['reading_date'],
                    'method' => 'manual',
                ]);

                $invoice = $billingService->billConnection(
                    $connection,
                    $reading,
                    $rateSchedule,
                    $period['start'],
                    $period['end'],
                );

                $targetStatus = $statuses[$periodIdx];

                if ($targetStatus === 'paid') {
                    $invoice->update(['status' => 'paid']);

                    Payment::create([
                        'invoice_id' => $invoice->id,
                        'amount' => $invoice->total_amount,
                        'method' => 'offline',
                        'paid_at' => $period['reading_date'],
                        'recorded_by' => $admin->id,
                    ]);
                } elseif ($targetStatus === 'overdue') {
                    $dueDate = date('Y-m-d', strtotime($period['end'] . ' +' . $graceDays . ' days'));
                    $invoice->update([
                        'status' => 'overdue',
                        'due_date' => $dueDate,
                    ]);
                }

                $previousReading = $presentReading;
            }
        }

        $this->printSummary();
    }

    private function printSummary(): void
    {
        $paidCount = Payment::query()->count();
        $overdueCount = Invoice::query()->where('status', 'overdue')->count();
        $unpaidCount = Invoice::query()->where('status', 'unpaid')->count();
        $totalInvoices = Invoice::query()->count();
        $totalReadings = MeterReading::query()->count();
        $totalLinks = ConnectionLink::query()->count();

        $totalReceivable = Invoice::query()
            ->whereIn('status', ['unpaid', 'overdue'])
            ->sum('total_amount');

        $totalPaid = Payment::query()->sum('amount');

        $this->command->newLine();
        $this->command->info('=== Test Data Summary ===');
        $this->command->table(
            ['Metric', 'Count'],
            [
                ['Connections linked to test user', $totalLinks],
                ['Meter readings created', $totalReadings],
                ['Total invoices', $totalInvoices],
                ['  — paid', $paidCount],
                ['  — overdue', $overdueCount],
                ['  — unpaid', $unpaidCount],
                ['Total paid amount (₱)', number_format($totalPaid, 2)],
                ['Total receivable (₱)', number_format($totalReceivable, 2)],
            ],
        );

        $this->command->info('Usage profiles applied:');
        $this->command->table(
            ['Connection #', 'Profile', 'Statuses'],
            collect(self::USAGE_PROFILES_ORDER)->map(fn ($profile, $idx) => [
                $idx + 1,
                $profile,
                implode(' / ', self::STATUS_MAP[$idx + 1]),
            ])->all(),
        );
    }
}

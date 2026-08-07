<?php

namespace Database\Seeders;

use App\Models\ConnectionLink;
use App\Models\Invoice;
use App\Models\MeterReading;
use App\Models\Payment;
use App\Models\RateSchedule;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DemoPortalDataSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Demo portal state for testing: one active link, three consecutive meter
     * readings, and three invoices (paid / overdue / unpaid) for the test user.
     * Amounts derive from the flat rate so the bill math is coherent.
     */
    public function run(): void
    {
        if (ConnectionLink::query()->exists()) {
            return;
        }

        $connection = ServiceConnection::query()->orderBy('id')->first();
        $testUser = User::query()->where('email', 'test@example.com')->first();

        if (! $connection || ! $testUser) {
            return;
        }

        $ratePerM3 = RateSchedule::query()->orderBy('effective_from')->value('flat_rate') ?? 10.00;
        $admin = User::query()->where('is_admin', true)->first();

        ConnectionLink::create([
            'user_id' => $testUser->id,
            'service_connection_id' => $connection->id,
            'status' => 'active',
            'linked_at' => now()->subMonths(3),
        ]);

        $periods = [
            ['start' => Carbon::now()->subMonths(2)->startOfMonth(), 'end' => Carbon::now()->subMonths(2)->endOfMonth(), 'prev' => 100.00, 'present' => 135.00, 'due_days' => 10, 'status' => 'paid'],
            ['start' => Carbon::now()->subMonths(1)->startOfMonth(), 'end' => Carbon::now()->subMonths(1)->endOfMonth(), 'prev' => 135.00, 'present' => 178.00, 'due_days' => null, 'status' => 'overdue'],
            ['start' => Carbon::now()->startOfMonth(), 'end' => Carbon::now()->endOfMonth(), 'prev' => 178.00, 'present' => 210.00, 'due_days' => 15, 'status' => 'unpaid'],
        ];

        $invoiceNumber = 1;

        foreach ($periods as $period) {
            $cuM = $period['present'] - $period['prev'];
            $base = round($cuM * $ratePerM3, 2);

            $reading = MeterReading::create([
                'service_connection_id' => $connection->id,
                'present_reading' => $period['present'],
                'previous_reading' => $period['prev'],
                'cu_m_used' => $cuM,
                'entered_by' => $admin?->id ?? $testUser->id,
                'entered_at' => $period['end']->copy()->endOfDay(),
                'method' => 'manual',
            ]);

            $penalty = 0.00;
            if ($period['status'] === 'overdue') {
                $penalty = round($base * 0.02, 2);
            }

            $invoice = Invoice::create([
                'invoice_number' => 'GW-' . now()->format('Y') . '-' . str_pad((string) $invoiceNumber, 5, '0', STR_PAD_LEFT),
                'service_connection_id' => $connection->id,
                'meter_reading_id' => $reading->id,
                'rate_schedule_id' => RateSchedule::query()->orderBy('effective_from')->first()?->id,
                'billing_period_start' => $period['start']->toDateString(),
                'billing_period_end' => $period['end']->toDateString(),
                'previous_balance' => 0.00,
                'base_amount' => $base,
                'penalty_amount' => $penalty,
                'total_amount' => round($base + $penalty, 2),
                'due_date' => $period['due_days'] !== null
                    ? $period['end']->copy()->addDays($period['due_days'])->toDateString()
                    : now()->subDays(10)->toDateString(),
                'status' => $period['status'],
            ]);

            if ($period['status'] === 'paid') {
                Payment::create([
                    'invoice_id' => $invoice->id,
                    'amount' => $invoice->total_amount,
                    'method' => 'offline',
                    'paid_at' => $period['end']->copy()->addDays(12),
                    'recorded_by' => $admin?->id,
                ]);
            }

            $invoiceNumber++;
        }
    }
}

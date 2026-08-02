<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\MeterReading;
use App\Models\PenaltyRule;
use App\Models\RateSchedule;
use App\Models\ServiceConnection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BillingService
{
    public function computeBaseAmount(float $cuMUsed, RateSchedule $schedule): float
    {
        if (strtolower((string) $schedule->type) === 'flat') {
            return round($cuMUsed * (float) $schedule->flat_rate, 2);
        }

        $amount = 0.0;

        foreach ($schedule->tiers()->orderBy('min_cu_m')->get() as $tier) {
            $tierMin = (float) $tier->min_cu_m;
            $tierMax = $tier->max_cu_m !== null ? (float) $tier->max_cu_m : INF;

            if ($cuMUsed <= $tierMin) {
                break;
            }

            $amount += (min($cuMUsed, $tierMax) - $tierMin) * (float) $tier->rate_per_cu_m;
        }

        return round($amount, 2);
    }

    public function findEffectiveSchedule(int $serviceConnectionId, string $periodEndDate, ?bool &$usedFallback = null): ?RateSchedule
    {
        $usedFallback = false;
        $connection = ServiceConnection::with('rateSchedule')->find($serviceConnectionId);

        if ($connection?->rate_schedule_id) {
            if ($this->isEffective($connection->rateSchedule, $periodEndDate)) {
                return $connection->rateSchedule;
            }

            $usedFallback = true;
        }

        return RateSchedule::query()
            ->where('effective_from', '<=', $periodEndDate)
            ->where(function ($query) use ($periodEndDate) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $periodEndDate);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    public function findEffectivePenaltyRule(string $asOfDate): ?PenaltyRule
    {
        return PenaltyRule::query()
            ->where('effective_from', '<=', $asOfDate)
            ->where(function ($query) use ($asOfDate) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $asOfDate);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    public function computePenalty(float $amount, string $dueDate, string $asOfDate, ?PenaltyRule $rule = null): float
    {
        if ($amount <= 0) {
            return 0.0;
        }

        $rule ??= $this->findEffectivePenaltyRule($asOfDate);

        if (! $rule) {
            return 0.0;
        }

        $penaltyStart = strtotime($dueDate.' +'.(int) $rule->grace_period_days.' days');
        $asOfTs = strtotime($asOfDate);

        if ($penaltyStart === false || $asOfTs === false) {
            return 0.0;
        }

        $daysOverdue = (int) floor(($asOfTs - $penaltyStart) / 86400);

        if ($daysOverdue <= 0) {
            return 0.0;
        }

        $fullMonths = intdiv($daysOverdue, 30);

        return round($amount * ((float) $rule->percent_per_month / 100) * $fullMonths, 2);
    }

    public function getUnpaidInvoices(int $serviceConnectionId): Collection
    {
        return Invoice::where('service_connection_id', $serviceConnectionId)
            ->whereIn('status', ['unpaid', 'overdue'])
            ->orderBy('due_date')
            ->get();
    }

    public function generateInvoiceNumber(): string
    {
        $latest = Invoice::query()->orderByDesc('id')->value('invoice_number');
        $sequence = $latest ? ((int) substr($latest, -5)) + 1 : 1;

        return 'GW-'.now()->format('Y').'-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }

    public function billConnection(
        ServiceConnection $connection,
        MeterReading $reading,
        RateSchedule $schedule,
        string $periodStart,
        string $periodEnd,
        ?PenaltyRule $penaltyRule = null,
    ): Invoice {
        $unpaid = $this->getUnpaidInvoices($connection->id);

        $previousBalance = round($unpaid->sum(fn (Invoice $invoice) => (float) $invoice->total_amount), 2);
        $penaltyAmount = round($unpaid->sum(fn (Invoice $invoice) => $this->computePenalty(
            (float) $invoice->total_amount,
            $invoice->due_date->toDateString(),
            $periodEnd,
            $penaltyRule,
        )), 2);
        $baseAmount = $this->computeBaseAmount((float) $reading->cu_m_used, $schedule);
        $totalAmount = round($previousBalance + $penaltyAmount + $baseAmount, 2);

        $graceDays = $penaltyRule?->grace_period_days ?? 15;
        $dueDate = date('Y-m-d', strtotime($periodEnd.' +'.$graceDays.' days'));

        return Invoice::create([
            'invoice_number' => $this->generateInvoiceNumber(),
            'service_connection_id' => $connection->id,
            'meter_reading_id' => $reading->id,
            'rate_schedule_id' => $schedule->id,
            'billing_period_start' => $periodStart,
            'billing_period_end' => $periodEnd,
            'previous_balance' => $previousBalance,
            'base_amount' => $baseAmount,
            'penalty_amount' => $penaltyAmount,
            'total_amount' => $totalAmount,
            'due_date' => $dueDate,
            'status' => 'unpaid',
        ]);
    }

    public function run(?string $periodEnd = null): Collection
    {
        $periodEnd ??= date('Y-m-d', strtotime('last day of previous month'));

        if (! $this->isValidPeriodEnd($periodEnd)) {
            throw new \InvalidArgumentException("Invalid billing period end date: {$periodEnd}");
        }

        $periodStart = date('Y-m-01', strtotime($periodEnd));
        $penaltyRule = $this->findEffectivePenaltyRule($periodEnd);

        return DB::transaction(function () use ($periodStart, $periodEnd, $penaltyRule): Collection {
            Invoice::where('status', 'unpaid')
                ->where('due_date', '<', $periodEnd)
                ->update(['status' => 'overdue']);

            $report = collect();

        foreach (ServiceConnection::where('status', 'active')->orderBy('id')->get() as $connection) {
            $reading = MeterReading::where('service_connection_id', $connection->id)
                ->where('entered_at', '>=', $periodStart.' 00:00:00')
                ->where('entered_at', '<', date('Y-m-d', strtotime($periodEnd.' +1 day')))
                ->latest('entered_at')
                ->first();

            if (! $reading) {
                $report->push($this->reportRow($connection, 'skipped', 'No reading in the billing period ('.$periodStart.' to '.$periodEnd.').'));
                continue;
            }

            if ($reading->flagged !== 0) {
                $report->push($this->reportRow($connection, 'skipped', "Flagged reading (level {$reading->flagged}) — investigate, then bill manually."));
                continue;
            }

            $alreadyBilled = Invoice::where('service_connection_id', $connection->id)
                ->where('meter_reading_id', $reading->id)
                ->exists();

            if ($alreadyBilled) {
                $report->push($this->reportRow($connection, 'skipped', 'Already billed for this reading.'));
                continue;
            }

            $cuMUsed = (float) $reading->cu_m_used;

            if ($cuMUsed < 0) {
                $report->push($this->reportRow($connection, 'skipped', "Non-positive usage ({$cuMUsed} cu.m.) — investigate, then bill manually."));
                continue;
            }

            if ($cuMUsed == 0) {
                $report->push($this->reportRow($connection, 'skipped', 'Zero usage — verify meter locked/closed, or bill manually.'));
                continue;
            }

            $usedFallback = false;
            $schedule = $this->findEffectiveSchedule($connection->id, $periodEnd, $usedFallback);

            if (! $schedule) {
                $report->push($this->reportRow($connection, 'skipped', 'No effective rate schedule for this period.'));
                continue;
            }

            if (! $this->scheduleCanCompute($schedule)) {
                $report->push($this->reportRow($connection, 'skipped', 'Rate schedule misconfigured ('.$schedule->name.') — missing flat rate or tiers.'));
                continue;
            }

            $invoice = $this->billConnection($connection, $reading, $schedule, $periodStart, $periodEnd, $penaltyRule);

            $report->push($this->reportRow(
                $connection,
                'billed',
                $usedFallback ? 'Global rate (assigned schedule not effective for this period).' : null,
                $invoice->invoice_number,
                (float) $invoice->total_amount,
            ));
        }

        return $report;
        });
    }

    private function reportRow(
        ServiceConnection $connection,
        string $status,
        ?string $reason,
        ?string $invoiceNumber = null,
        ?float $totalAmount = null,
    ): array {
        return [
            'account_number' => $connection->account_number,
            'registered_name' => $connection->registered_name,
            'status' => $status,
            'reason' => $reason,
            'invoice_number' => $invoiceNumber,
            'total_amount' => $totalAmount,
        ];
    }

    private function scheduleCanCompute(RateSchedule $schedule): bool
    {
        if (strtolower((string) $schedule->type) === 'flat') {
            return $schedule->flat_rate !== null && (float) $schedule->flat_rate > 0;
        }

        return $schedule->tiers()->count() > 0;
    }

    private function isEffective(RateSchedule $schedule, string $periodEndDate): bool
    {
        $effectiveTo = $schedule->effective_to?->toDateString();

        return $schedule->effective_from->toDateString() <= $periodEndDate
            && ($effectiveTo === null || $effectiveTo >= $periodEndDate);
    }

    private function isValidPeriodEnd(string $periodEnd): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $periodEnd, $matches)) {
            return false;
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }
}

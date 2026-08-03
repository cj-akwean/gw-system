<?php

namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;

class PdfService
{
    public function generate(Invoice $invoice): string
    {
        $data = $this->buildViewData($invoice);

        $html = view('pdfs.invoice', $data)->render();

        return Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->setOption('enable_font_subsetting', true)
            ->output();
    }

    public function buildViewData(Invoice $invoice): array
    {
        $invoice->load(['serviceConnection.barangay', 'meterReading', 'rateSchedule']);

        $connection = $invoice->serviceConnection;
        $reading = $invoice->meterReading;

        $formatDate = static function (Carbon|string|null $value): string {
            if (! $value) {
                return '—';
            }
            if ($value instanceof Carbon) {
                return $value->format('M d, Y');
            }
            $date = Carbon::parse($value);

            return $date->isValid() ? $date->format('M d, Y') : $value;
        };

        $barangay = optional($connection?->barangay)->name;
        $addressLine = trim((string) optional($connection)->address.' '.$barangay);
        if ($addressLine === '') {
            $addressLine = '—';
        }

        return [
            'invoiceNumber' => $invoice->invoice_number,
            'accountNumber' => $connection->account_number ?? '—',
            'meterNumber' => $connection->meter_number ?? '—',
            'customerName' => $connection->registered_name ?? '—',
            'addressLine' => $addressLine,
            'presentReading' => $reading?->present_reading ?? '—',
            'previousReading' => $reading?->previous_reading ?? '—',
            'cuMUsed' => $reading?->cu_m_used ?? '—',
            'rateDisplay' => $this->describeRate($invoice),
            'status' => ucfirst($invoice->status ?? ''),
            'billingPeriodStart' => $formatDate($invoice->billing_period_start),
            'billingPeriodEnd' => $formatDate($invoice->billing_period_end),
            'dueDate' => $formatDate($invoice->due_date),
            'issuedAt' => now()->format('M d, Y'),
            'currentCharges' => (float) $invoice->base_amount,
            'arrears' => (float) $invoice->previous_balance,
            'penalty' => (float) $invoice->penalty_amount,
            'penaltyLabel' => $this->describePenalty($invoice),
            'total' => (float) $invoice->total_amount,
        ];
    }

    protected function describeRate(Invoice $invoice): string
    {
        $reading = $invoice->meterReading;
        $schedule = $invoice->rateSchedule;
        $usage = (float) ($reading?->cu_m_used ?? 0);
        $base = (float) $invoice->base_amount;

        if ($schedule && strtolower((string) $schedule->type) === 'flat') {
            $rate = $usage > 0 ? ($base / $usage) : (float) $schedule->flat_rate;

            return number_format($rate, 2)." / cu.m.";
        }

        return $schedule ? ($schedule->name.' (see schedule)') : 'Rate schedule N/A';
    }

    protected function describePenalty(Invoice $invoice): string
    {
        $periodEnd = $invoice->billing_period_end?->toDateString();

        if ($periodEnd === null) {
            return 'Penalty';
        }

        $rule = app(BillingService::class)->findEffectivePenaltyRule($periodEnd);

        if ($rule && (float) $rule->percent_per_month > 0) {
            $percent = number_format((float) $rule->percent_per_month, 2);

            return "Penalty ({$percent}%/mo on unpaid)";
        }

        return 'Penalty';
    }
}

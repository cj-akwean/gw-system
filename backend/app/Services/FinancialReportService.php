<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Builds the accounting dataset shared by the Filament "Accounting &
 * Finance" page, the Excel export and the PDF export, so the numbers can
 * never drift between the three renderings.
 *
 * The Dashboard owns the operational KPIs (active customers, unpaid/overdue
 * bills, revenue chart); this service only produces formal accounting
 * content: AR aging, statement of income and receivable/collection totals
 * for a date range.
 */
class FinancialReportService
{
    /**
     * @return array{
     *     generatedAt: string,
     *     range: array{from: CarbonImmutable, to: CarbonImmutable, label: string},
     *     summary: array{total_receivables: float, total_collections: float},
     *     aging: Collection<int, array{key: string, label: string, range_label: string, count: int, amount: float, penalty: float}>,
     *     income: array{
     *         gross_billed: float,
     *         cash_collections: float,
     *         misc_income: float,
     *         reconnection_fees: float,
     *         setup_fees: float,
     *         net_operating_income: float,
     *     },
     * }
     */
    public function build(?string $from = null, ?string $to = null, ?CarbonInterface $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();
        $range = $this->normalizeRange($from, $to);

        return [
            'generatedAt' => $asOf->format('M d, Y h:i A'),
            'range' => $range,
            'summary' => [
                'total_receivables' => $this->receivablesTotal(),
                'total_collections' => $this->collectionsBetween($range['from'], $range['to']),
            ],
            'aging' => $this->agingBuckets($asOf),
            'income' => $this->incomeStatement($range['from'], $range['to']),
        ];
    }

    /**
     * Clamp the requested range: missing bounds default to the current
     * calendar month, and a reversed range (from > to) is swapped back.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable, label: string}
     */
    public function normalizeRange(?string $from, ?string $to): array
    {
        $from = $this->parseDate($from) ?? CarbonImmutable::now()->startOfMonth();
        $to = $this->parseDate($to) ?? CarbonImmutable::now()->endOfMonth();

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $from = $from->startOfDay();
        $to = $to->endOfDay();

        $label = $from->isSameDay($to->startOfDay())
            ? $from->format('M d, Y')
            : $from->format('M d, Y').' – '.$to->format('M d, Y');

        return compact('from', 'to', 'label');
    }

    /**
     * Outstanding receivables: sum of unpaid + overdue invoice totals.
     * Partial payments are not deducted because the system only records
     * full payments (an invoice stays unpaid/overdue until its total is
     * collected). Always equals the sum of the aging buckets.
     */
    public function receivablesTotal(): float
    {
        return (float) Invoice::query()
            ->whereIn('status', ['unpaid', 'overdue'])
            ->sum('total_amount');
    }

    /**
     * Actual cash collections within a range, cash-basis (paid_at).
     */
    public function collectionsBetween(CarbonInterface $from, CarbonInterface $to): float
    {
        return (float) Payment::query()
            ->whereBetween('paid_at', [$from, $to])
            ->sum('amount');
    }

    /**
     * AR aging as of a date, bucketed by whole days past due_date.
     * Invoices not yet due (future due_date) count as Current. A day is
     * counted once; a due_date exactly N days ago falls in the bucket whose
     * lower bound is N (0–30, 31–60, 61–90, 90+).
     *
     * Penalties per bucket use the stored invoice.penalty_amount (the
     * auditable figure charged at billing time), matching the income
     * statement's miscellaneous-income treatment.
     *
     * @return Collection<int, array{key: string, label: string, range_label: string, count: int, amount: float, penalty: float}>
     */
    public function agingBuckets(?CarbonInterface $asOf = null): Collection
    {
        $asOf ??= CarbonImmutable::now();
        $today = $asOf->startOfDay();

        $invoices = Invoice::query()
            ->whereIn('status', ['unpaid', 'overdue'])
            ->whereNotNull('due_date')
            ->get(['id', 'due_date', 'total_amount', 'penalty_amount']);

        $buckets = collect([
            ['key' => 'current', 'label' => 'Current (0–30 days)', 'count' => 0, 'amount' => 0.0, 'penalty' => 0.0],
            ['key' => 'd31_60', 'label' => '31–60 days', 'count' => 0, 'amount' => 0.0, 'penalty' => 0.0],
            ['key' => 'd61_90', 'label' => '61–90 days', 'count' => 0, 'amount' => 0.0, 'penalty' => 0.0],
            ['key' => 'overdue90', 'label' => '90+ days (Overdue)', 'count' => 0, 'amount' => 0.0, 'penalty' => 0.0],
        ]);

        foreach ($invoices as $invoice) {
            $ageDays = (int) floor($invoice->due_date->diffInDays($today, false));

            $key = match (true) {
                $ageDays <= 30 => 'current',
                $ageDays <= 60 => 'd31_60',
                $ageDays <= 90 => 'd61_90',
                default => 'overdue90',
            };

            $bucket = $buckets->firstWhere('key', $key);
            $bucket['count']++;
            $bucket['amount'] = round((float) $bucket['amount'] + (float) $invoice->total_amount, 2);
            $bucket['penalty'] = round((float) $bucket['penalty'] + (float) $invoice->penalty_amount, 2);
            $buckets = $buckets->map(fn (array $row): array => $row['key'] === $key ? $bucket : $row);
        }

        return $buckets->map(function (array $row): array {
            $row['range_label'] = match ($row['key']) {
                'current' => '0–30 days past due',
                'd31_60' => '31–60 days past due',
                'd61_90' => '61–90 days past due',
                default => 'more than 90 days past due',
            };

            return $row;
        });
    }

    /**
     * Cash vs. accrual statement of income for a range.
     *
     * - Gross billed revenue is accrual: total invoiced amount whose
     *   billing period ends inside the range (revenue recognized when billed,
     *   whether or not collected yet).
     * - Cash collections are cash-basis payments recorded inside the range.
     * - Miscellaneous income currently only has penalty charges; reconnection
     *   and setup fees are not tracked yet and report as ₱0.
     * - Net operating income = (gross billed + misc) − cash collections, the
     *   accrued-vs-collected spread.
     *
     * @return array{
     *     gross_billed: float,
     *     cash_collections: float,
     *     misc_income: float,
     *     reconnection_fees: float,
     *     setup_fees: float,
     *     net_operating_income: float,
     * }
     */
    public function incomeStatement(CarbonInterface $from, CarbonInterface $to): array
    {
        $billed = Invoice::query()
            ->whereBetween('billing_period_end', [$from->toDateString(), $to->toDateString()]);

        $grossBilled = (float) (clone $billed)->sum('total_amount');
        $miscIncome = (float) (clone $billed)->sum('penalty_amount');

        $collections = $this->collectionsBetween($from, $to);

        return [
            'gross_billed' => round($grossBilled, 2),
            'cash_collections' => round($collections, 2),
            'misc_income' => round($miscIncome, 2),
            'reconnection_fees' => 0.0,
            'setup_fees' => 0.0,
            'net_operating_income' => round($grossBilled + $miscIncome - $collections, 2),
        ];
    }

    /**
     * Payment ledger rows within a range, newest first, for the reconciliation
     * sheet and PDF. One row per recorded payment.
     *
     * @return Collection<int, array{
     *     id: int,
     *     invoice_number: string,
     *     account_number: string,
     *     customer_name: string,
     *     paid_at: string,
     *     method: string,
     *     amount: float,
     *     reference: string,
     * }>
     */
    public function ledgerRows(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return Payment::query()
            ->with(['invoice.serviceConnection'])
            ->whereBetween('paid_at', [$from, $to])
            ->orderByDesc('paid_at')
            ->get()
            ->map(fn (Payment $payment): array => [
                'id' => (int) $payment->id,
                'invoice_number' => (string) ($payment->invoice?->invoice_number ?? ''),
                'account_number' => (string) ($payment->invoice?->serviceConnection?->account_number ?? ''),
                'customer_name' => (string) ($payment->invoice?->serviceConnection?->registered_name ?? ''),
                'paid_at' => $payment->paid_at?->format('M d, Y h:i A') ?? '',
                'method' => \App\Filament\Resources\PaymentResource::methodLabel($payment->method, $payment->paymongo_source),
                'amount' => (float) $payment->amount,
                'reference' => (string) ($payment->reference ?? $payment->paymongo_reference ?? '—'),
            ]);
    }

    private function parseDate(?string $value): ?CarbonImmutable    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}

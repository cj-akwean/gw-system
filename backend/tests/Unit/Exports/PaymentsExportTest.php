<?php

namespace Tests\Unit\Exports;

use App\Exports\PaymentsExport;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PaymentsExportTest extends TestCase
{
    private function exportFor(Payment $payment): PaymentsExport
    {
        return new PaymentsExport(Payment::query());
    }

    public function test_headings_match_the_specification(): void
    {
        $export = $this->exportFor(new Payment());

        $this->assertSame([
            'paid_at',
            'invoice_no',
            'account_no',
            'meter_no',
            'customer_name',
            'amount',
            'method',
            'reference',
            'payer_name',
            'payer_email',
            'recorded_by',
        ], $export->headings());
    }

    public function test_map_outputs_a_fully_populated_row(): void
    {
        $connection = (new ServiceConnection())
            ->forceFill(['account_number' => 'GW-00001', 'meter_number' => 'MTR-00001', 'registered_name' => 'Ana Dela Cruz']);
        $invoice = (new Invoice())->forceFill(['invoice_number' => 'GW-2026-00001'])->setRelation('serviceConnection', $connection);
        $clerk = (new User())->setAttribute('name', 'Office Clerk');

        $payment = (new Payment())->forceFill([
            'amount' => 500.00,
            'method' => 'paymongo',
            'paymongo_reference' => 'pay_test_1',
            'paymongo_source' => 'gcash',
            'payer_name' => 'Ben Santos',
            'payer_email' => 'ben@example.com',
        ])
            ->setRelation('invoice', $invoice)
            ->setRelation('recordedBy', $clerk);

        $payment->paid_at = \Illuminate\Support\Carbon::parse('2026-08-01 10:30:00');

        $row = $this->exportFor($payment)->map($payment);

        $this->assertSame([
            '2026-08-01 10:30:00',
            'GW-2026-00001',
            'GW-00001',
            'MTR-00001',
            'Ana Dela Cruz',
            '500.00',
            'PayMongo · GCash',
            'pay_test_1',
            'Ben Santos',
            'ben@example.com',
            'Office Clerk',
        ], $row);
    }

    public function test_map_handles_an_invoice_without_a_service_connection(): void
    {
        $invoice = (new Invoice())
            ->forceFill(['invoice_number' => 'GW-123-000002'])
            ->setRelation('serviceConnection', null);

        $payment = (new Payment())->forceFill([
            'amount' => 100.00,
            'method' => 'cash',
            'reference' => 'OR-100',
        ])
            ->setRelation('invoice', $invoice)
            ->setRelation('recordedBy', null);

        $payment->paid_at = \Illuminate\Support\Carbon::parse('2026-08-01 09:00:00');

        $row = $this->exportFor($payment)->map($payment);

        $this->assertSame('GW-123-000002', $row[1]);
        $this->assertSame('', $row[2]);
        $this->assertSame('', $row[3]);
        $this->assertSame('', $row[4]);
    }

    public function test_map_reports_paymongo_for_webhook_payments_with_no_recorder(): void
    {
        $invoice = (new Invoice())
            ->forceFill(['invoice_number' => 'GW-2026-00002'])
            ->setRelation('serviceConnection', null);

        $payment = (new Payment())->forceFill([
            'amount' => 100.00,
            'method' => 'paymongo',
            'paymongo_reference' => 'pay_test_2',
        ])
            ->setRelation('invoice', $invoice)
            ->setRelation('recordedBy', null);

        $payment->paid_at = \Illuminate\Support\Carbon::parse('2026-08-01 10:00:00');

        $row = $this->exportFor($payment)->map($payment);

        $this->assertSame('PayMongo', $row[10]);
    }

    public function test_map_escapes_formula_injection_in_payer_fields(): void
    {
        $invoice = (new Invoice())->setRelation('serviceConnection', null);

        $payment = (new Payment())->forceFill([
            'amount' => 100.00,
            'method' => 'paymongo',
            'paymongo_reference' => 'pay_test_2',
            'payer_name' => '=cmd()',
            'payer_email' => '@spam.com',
        ])
            ->setRelation('invoice', $invoice)
            ->setRelation('recordedBy', null);

        $payment->paid_at = \Illuminate\Support\Carbon::parse('2026-08-01 10:00:00');

        $row = $this->exportFor($payment)->map($payment);

        $this->assertSame("'=cmd()", $row[8]);
        $this->assertSame("'@spam.com", $row[9]);
    }

    public function test_map_escapes_tab_and_carriage_return_prefixed_formula_injection(): void
    {
        $invoice = (new Invoice())->setRelation('serviceConnection', null);

        $payment = (new Payment())->forceFill([
            'amount' => 100.00,
            'method' => 'cash',
            'reference' => 'OR-100',
            'payer_name' => "\t=cmd()",
            'payer_email' => "\r=cmd()",
        ])
            ->setRelation('invoice', $invoice)
            ->setRelation('recordedBy', null);

        $payment->paid_at = \Illuminate\Support\Carbon::parse('2026-08-01 10:00:00');

        $row = $this->exportFor($payment)->map($payment);

        $this->assertSame("'\t=cmd()", $row[8]);
        $this->assertSame("'\r=cmd()", $row[9]);
    }

    public function test_map_outputs_an_empty_paid_at_for_a_null_date(): void
    {
        $invoice = (new Invoice())->setRelation('serviceConnection', null);

        $payment = (new Payment())->forceFill([
            'amount' => 100.00,
            'method' => 'cash',
            'reference' => 'OR-100',
        ])
            ->setRelation('invoice', $invoice)
            ->setRelation('recordedBy', null);

        $row = $this->exportFor($payment)->map($payment);

        $this->assertNull($row[0]);
    }
}
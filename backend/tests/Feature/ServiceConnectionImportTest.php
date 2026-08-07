<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\RateSchedule;
use App\Models\ServiceConnection;
use App\Services\ServiceConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ServiceConnectionImportTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ServiceConnectionService
    {
        return app(ServiceConnectionService::class);
    }

    private function barangay(string $name = 'Poblacion'): Barangay
    {
        return Barangay::factory()->create(['name' => $name]);
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Juan Dela Cruz',
            'barangay' => 'Poblacion',
            'address' => '123 Main St',
        ], $overrides);
    }

    // --- header validation -------------------------------------------------

    public function test_validate_headers_rejects_missing_required_columns(): void
    {
        $errors = $this->service()->validateHeaders(['name' => 'x', 'barangay' => 'y']);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('address', $errors[0]);

        $errors = $this->service()->validateHeaders(['present_reading' => 'x']);

        $this->assertCount(3, $errors);
    }

    public function test_validate_headers_accepts_export_round_trip_set(): void
    {
        // The CSV export carries extra columns (pending_balance, created_at,
        // rate_schedule, ...) that the import must accept and ignore.
        $errors = $this->service()->validateHeaders([
            'account_number' => 'a',
            'meter_number' => 'm',
            'name' => 'n',
            'barangay' => 'b',
            'address' => 'a',
            'phone' => 'p',
            'email' => 'e',
            'gender' => 'g',
            'birthdate' => 'bd',
            'civil_status' => 'cs',
            'occupation' => 'o',
            'status' => 's',
            'connection_date' => 'cd',
            'rate_schedule' => 'rs',
            'pending_balance' => 'pb',
            'created_at' => 'ca',
        ]);

        $this->assertSame([], $errors);
    }

    // --- auto-generated identifiers ---------------------------------------

    public function test_blank_identifiers_auto_generated_on_empty_db(): void
    {
        $this->barangay();

        $results = $this->service()->prepareImportRows([
            $this->row(['name' => 'One']),
            $this->row(['name' => 'Two']),
        ]);

        [$first, $second] = [$results[0], $results[1]];

        $this->assertTrue($first['valid']);
        $this->assertTrue($second['valid']);
        $this->assertSame('GW-00001', $first['data']['account_number']);
        $this->assertSame('MTR-00001', $first['data']['meter_number']);
        $this->assertSame('GW-00002', $second['data']['account_number']);
        $this->assertSame('MTR-00002', $second['data']['meter_number']);
        $this->assertTrue($first['generated']['account_number']);
        $this->assertTrue($first['generated']['meter_number']);
        $this->assertTrue($second['generated']['account_number']);
        $this->assertStringContainsString('auto-generated', $first['notes']);
    }

    public function test_auto_generated_identifier_skips_values_claimed_by_earlier_rows(): void
    {
        $this->barangay();

        $results = $this->service()->prepareImportRows([
            $this->row(['account_number' => 'GW-00001', 'meter_number' => 'MTR-00001']),
            $this->row(['name' => 'Blank Account']),
        ]);

        $this->assertSame('GW-00002', $results[1]['data']['account_number']);
        $this->assertSame('MTR-00002', $results[1]['data']['meter_number']);
    }

    public function test_office_issued_identifiers_do_not_block_the_sequence(): void
    {
        $this->barangay();

        ServiceConnection::factory()->create([
            'account_number' => 'ABC-XYZ',
            'meter_number' => 'H-409912',
        ]);

        $results = $this->service()->prepareImportRows([$this->row(['name' => 'Office Issued Only'])]);

        $this->assertTrue($results[0]['valid']);
        $this->assertSame('GW-00001', $results[0]['data']['account_number']);
        $this->assertSame('MTR-00001', $results[0]['data']['meter_number']);
    }

    public function test_provided_identifiers_are_kept_and_marked_not_generated(): void
    {
        $this->barangay();

        $results = $this->service()->prepareImportRows([
            $this->row([
                'account_number' => 'H-100',
                'meter_number' => 'MTR-OFFICIAL',
                'connection_date' => '2026-01-05',
            ]),
        ]);

        $this->assertTrue($results[0]['valid']);
        $this->assertSame('H-100', $results[0]['data']['account_number']);
        $this->assertSame('MTR-OFFICIAL', $results[0]['data']['meter_number']);
        $this->assertFalse($results[0]['generated']['account_number']);
        $this->assertSame('2026-01-05', $results[0]['data']['connection_date']);
        $this->assertSame('', $results[0]['notes']);
    }

    public function test_duplicate_identifier_within_file_is_invalid(): void
    {
        $this->barangay();

        $results = $this->service()->prepareImportRows([
            $this->row(['account_number' => 'GW-00001', 'meter_number' => 'MTR-00001']),
            $this->row(['name' => 'Dup', 'account_number' => 'GW-00001']),
        ]);

        $this->assertTrue($results[0]['valid']);
        $this->assertFalse($results[1]['valid']);
        $this->assertStringContainsString('already appears in this file (row 2)', $results[1]['notes']);
    }

    public function test_provided_value_colliding_with_generated_one_names_the_generating_row(): void
    {
        $this->barangay();

        $results = $this->service()->prepareImportRows([
            $this->row(['name' => 'Generates first']),
            $this->row(['name' => 'Provides the same', 'account_number' => 'GW-00001', 'meter_number' => 'MTR-00001']),
        ]);

        $this->assertTrue($results[0]['valid']);
        $this->assertFalse($results[1]['valid']);
        $this->assertStringContainsString('already appears in this file (row 2)', $results[1]['notes']);
    }

    public function test_identifier_already_in_database_is_invalid(): void
    {
        $this->barangay();

        ServiceConnection::factory()->create(['account_number' => 'GW-00001']);

        $results = $this->service()->prepareImportRows([
            $this->row(['account_number' => 'GW-00001']),
        ]);

        $this->assertFalse($results[0]['valid']);
        $this->assertStringContainsString('already exists', $results[0]['notes']);
    }

    // --- entity-resolution validation -------------------------------------

    public function test_unknown_barangay_is_invalid_and_real_one_matches_case_insensitively(): void
    {
        $this->barangay('San Rafael');

        $results = $this->service()->prepareImportRows([
            $this->row(['barangay' => 'Nowhere Ville']),
            $this->row(['name' => 'Second', 'barangay' => 'san rafael']),
        ]);

        $this->assertFalse($results[0]['valid']);
        $this->assertStringContainsString('Unknown barangay: Nowhere Ville', $results[0]['notes']);
        $this->assertTrue($results[1]['valid']);
        $this->assertNotNull($results[1]['data']['barangay_id']);
        $this->assertSame('San Rafael', $results[1]['barangay']);
    }

    public function test_rate_schedule_resolves_by_name_and_unknown_is_invalid(): void
    {
        $this->barangay();
        $schedule = RateSchedule::factory()->create(['name' => 'Standard Flat Rate']);

        $results = $this->service()->prepareImportRows([
            $this->row(['name' => 'With Schedule', 'rate_schedule' => 'Standard Flat Rate']),
            $this->row(['name' => 'Bad Schedule', 'rate_schedule' => 'No Such Schedule']),
        ]);

        $this->assertTrue($results[0]['valid']);
        $this->assertSame($schedule->id, $results[0]['data']['rate_schedule_id']);
        $this->assertFalse($results[1]['valid']);
        $this->assertStringContainsString('Unknown rate schedule', $results[1]['notes']);
    }

    public function test_ambiguous_rate_schedule_name_is_invalid(): void
    {
        $this->barangay();
        RateSchedule::factory()->create(['name' => 'Standard Flat Rate']);
        RateSchedule::factory()->create(['name' => 'Standard Flat Rate']);

        $results = $this->service()->prepareImportRows([
            $this->row(['rate_schedule' => 'Standard Flat Rate']),
        ]);

        $this->assertFalse($results[0]['valid']);
        $this->assertStringContainsString('Multiple rate schedules', $results[0]['notes']);
    }

    // --- field validation --------------------------------------------------

    public function test_missing_required_fields_invalid(): void
    {
        $this->barangay();

        $results = $this->service()->prepareImportRows([
            ['name' => '', 'barangay' => '', 'address' => ''],
        ]);

        $this->assertFalse($results[0]['valid']);
        $this->assertStringContainsString('name', $results[0]['notes']);
        $this->assertStringContainsString('barangay', $results[0]['notes']);
        $this->assertStringContainsString('address', $results[0]['notes']);
    }

    public function test_invalid_gender_civil_status_and_status_values_rejected(): void
    {
        $this->barangay();

        $results = $this->service()->prepareImportRows([
            $this->row(['gender' => 'unknown', 'civil_status' => 'divorced', 'status' => 'archived']),
        ]);

        $this->assertFalse($results[0]['valid']);
        $this->assertStringContainsString('gender', $results[0]['notes']);
        $this->assertStringContainsString('civil_status', $results[0]['notes']);
        $this->assertStringContainsString('status', $results[0]['notes']);
    }

    public function test_status_defaults_to_active_and_connection_date_to_today(): void
    {
        $this->barangay();

        $results = $this->service()->prepareImportRows([
            $this->row(['name' => 'Defaults']),
        ]);

        $this->assertTrue($results[0]['valid']);
        $this->assertSame('active', $results[0]['data']['status']);
        $this->assertSame(now()->format('Y-m-d'), $results[0]['data']['connection_date']);
        $this->assertStringContainsString('defaulted to today', $results[0]['notes']);
    }

    public function test_future_birthdate_rejected(): void
    {
        $this->barangay();

        $results = $this->service()->prepareImportRows([
            $this->row(['birthdate' => now()->addYear()->format('Y-m-d')]),
        ]);

        $this->assertFalse($results[0]['valid']);
        $this->assertStringContainsString('not be in the future', $results[0]['notes']);
    }

    public function test_malformed_dates_rejected(): void
    {
        $this->barangay();

        $results = $this->service()->prepareImportRows([
            $this->row(['birthdate' => 'not-a-date']),
            $this->row(['name' => 'Bad Date', 'connection_date' => '2026-13-45']),
        ]);

        $this->assertFalse($results[0]['valid']);
        $this->assertStringContainsString('Invalid birthdate', $results[0]['notes']);
        $this->assertFalse($results[1]['valid']);
        $this->assertStringContainsString('Invalid connection_date', $results[1]['notes']);
    }

    public function test_invalid_phone_and_email_rejected(): void
    {
        $this->barangay();

        $results = $this->service()->prepareImportRows([
            $this->row(['phone' => 'asdf;;;', 'email' => 'not-an-email']),
        ]);

        $this->assertFalse($results[0]['valid']);
        $this->assertStringContainsString('Phone', $results[0]['notes']);
        $this->assertStringContainsString('Email', $results[0]['notes']);
    }

    public function test_export_round_trip_apostrophe_is_stripped(): void
    {
        $this->barangay();

        // The CSV exporter prefixes formula-like cells with a protective
        // apostrophe; re-importing an exported master list must restore the
        // original value rather than storing the literal apostrophe.
        $results = $this->service()->prepareImportRows([
            $this->row(['name' => "'=cmd", 'phone' => "'+639171234567"]),
        ]);

        $this->assertTrue($results[0]['valid']);
        $this->assertSame('=cmd', $results[0]['data']['registered_name']);
        $this->assertSame('+639171234567', $results[0]['data']['phone']);
    }

    public function test_numeric_cells_are_cast_to_string_without_scientific_notation(): void
    {
        $this->barangay();

        $results = $this->service()->prepareImportRows([
            $this->row(['phone' => 12345678901, 'name' => 'Numeric']),
        ]);

        $this->assertTrue($results[0]['valid']);
        $this->assertSame('12345678901', $results[0]['data']['phone']);
    }

    public function test_impossible_date_is_rejected_instead_of_silently_shifted(): void
    {
        $this->barangay();

        $results = $this->service()->prepareImportRows([
            $this->row(['birthdate' => '2026-02-30', 'name' => 'Shifted']),
            $this->row(['name' => 'Relative', 'birthdate' => 'next tuesday']),
        ]);

        $this->assertFalse($results[0]['valid']);
        $this->assertStringContainsString('Invalid birthdate', $results[0]['notes']);
        $this->assertFalse($results[1]['valid']);
    }

    public function test_slash_and_dash_dates_still_accepted(): void
    {
        $this->barangay();

        $results = $this->service()->prepareImportRows([
            $this->row(['name' => 'Slashy', 'birthdate' => '01/15/1990', 'connection_date' => '08/01/2026']),
        ]);

        $this->assertTrue($results[0]['valid']);
        $this->assertSame('1990-01-15', $results[0]['data']['birthdate']);
        $this->assertSame('2026-08-01', $results[0]['data']['connection_date']);
    }

    // --- createWithIdentifierBackstops ------------------------------------

    public function test_create_persists_import_data_with_good_rates_and_nullables(): void
    {
        $barangay = $this->barangay();
        $schedule = RateSchedule::factory()->create(['name' => 'Rate A']);

        $results = $this->service()->prepareImportRows([
            $this->row([
                'account_number' => 'GW-00007',
                'meter_number' => 'M-0007',
                'gender' => 'male',
                'civil_status' => 'married',
                'birthdate' => '1990-01-15',
                'phone' => '0917-123-4567',
                'email' => 'juan@example.com',
                'occupation' => 'Teacher',
                'status' => 'pending',
                'connection_date' => '2026-08-01',
                'rate_schedule' => 'Rate A',
            ]),
        ]);

        $record = $this->service()->createWithIdentifierBackstops($results[0]['data']);

        $this->assertSame('GW-00007', $record->account_number);
        $this->assertSame('M-0007', $record->meter_number);
        $this->assertSame($barangay->id, $record->barangay_id);
        $this->assertSame($schedule->id, $record->rate_schedule_id);
        $this->assertSame('pending', $record->status);
        $this->assertSame('1990-01-15', $record->birthdate->toDateString());
    }

    public function test_create_rolls_forward_generated_identifier_on_stale_collision(): void
    {
        $this->barangay();

        ServiceConnection::factory()->create([
            'account_number' => 'GW-00001',
            'meter_number' => 'MTR-00001',
        ]);

        $record = $this->service()->createWithIdentifierBackstops([
            'account_number' => 'GW-00001',
            'meter_number' => 'MTR-00001',
            'registered_name' => 'Rolled Forward',
            'barangay_id' => Barangay::first()->id,
            'address' => 'Anywhere',
            'status' => 'active',
            'connection_date' => '2026-08-07',
        ], ['account_number' => true, 'meter_number' => true]);

        $this->assertSame('GW-00002', $record->account_number);
        $this->assertSame('MTR-00002', $record->meter_number);

        $this->assertDatabaseHas('service_connections', ['account_number' => 'GW-00002', 'registered_name' => 'Rolled Forward']);
    }

    public function test_create_collision_on_generated_looking_but_user_provided_identifier_throws(): void
    {
        $this->barangay();

        ServiceConnection::factory()->create([
            'account_number' => 'GW-00009',
            'meter_number' => 'MTR-00009',
        ]);

        $this->expectException(ValidationException::class);

        try {
            // Machine-format, but the caller did NOT generate it (the preview
            // flagged this identifier as provided), so it must never be
            // silently renumbered to the next free value.
            $this->service()->createWithIdentifierBackstops([
                'account_number' => 'GW-00009',
                'meter_number' => 'MTR-00009',
                'registered_name' => 'Office Issued',
                'barangay_id' => Barangay::first()->id,
                'address' => 'Anywhere',
                'status' => 'active',
                'connection_date' => '2026-08-07',
            ], ['account_number' => false, 'meter_number' => false]);
        } finally {
            $this->assertCount(1, ServiceConnection::where('account_number', 'GW-00009')->get());
            $this->assertDatabaseMissing('service_connections', ['registered_name' => 'Office Issued']);
        }
    }

    public function test_create_collision_on_non_generated_identifier_throws(): void
    {
        $this->barangay();

        ServiceConnection::factory()->create([
            'account_number' => 'GW-REAL-900',
            'meter_number' => 'COMPETITOR-1',
        ]);

        $this->expectException(ValidationException::class);

        try {
            $this->service()->createWithIdentifierBackstops([
                'account_number' => 'GW-REAL-900',
                'meter_number' => 'MTR-REAL-777',
                'registered_name' => 'Office Issued',
                'barangay_id' => Barangay::first()->id,
                'address' => 'Anywhere',
                'status' => 'active',
                'connection_date' => '2026-08-07',
            ]);
        } finally {
            $this->assertSame('COMPETITOR-1', ServiceConnection::where('account_number', 'GW-REAL-900')->first()->meter_number);
            $this->assertDatabaseMissing('service_connections', ['registered_name' => 'Office Issued']);
        }
    }
}

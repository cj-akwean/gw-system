<?php

namespace Tests\Feature;

use App\Models\MeterReading;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\ReadingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReadingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_validate_reading_rejects_non_active_connections(): void
    {
        $service = new ReadingService;
        $connection = ServiceConnection::factory()->create(['status' => 'pending']);

        $result = $service->validateReading($connection->id, 100.0);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('is not active', $result['errors'][0]);
        $this->assertStringContainsString('pending', $result['errors'][0]);
    }

    public function test_reading_date_rejects_future_dates(): void
    {
        $service = new ReadingService;

        $this->assertSame(['Reading date cannot be in the future.'], $service->validateReadingDate('2026-09-01'));
    }

    public function test_reading_date_rejects_less_than_30_days_after_previous_reading(): void
    {
        $service = new ReadingService;

        $errors = $service->validateReadingDate('2026-07-20', '2026-07-01');

        $this->assertStringContainsString('at least 30 days after the previous reading', $errors[0]);
    }

    public function test_reading_date_allows_exactly_30_days_after_previous_reading(): void
    {
        $service = new ReadingService;

        $this->assertSame([], $service->validateReadingDate('2026-07-31', '2026-07-01'));
    }

    public function test_reading_date_has_no_age_limit_for_first_reading(): void
    {
        $service = new ReadingService;

        $this->assertSame([], $service->validateReadingDate('2026-06-01'));
    }

    public function test_csv_only_flag_gets_honest_note(): void
    {
        $service = app(ReadingService::class);
        $user = User::factory()->create();
        $connection = ServiceConnection::factory()->create();

        $results = $service->prepareImportRows([
            [
                'account_number' => $connection->account_number,
                'present_reading' => '100.00',
                'reading_date' => '2026-07-30',
                'flagged' => '1',
            ],
        ], $user);

        $row = $results->first();

        $this->assertTrue($row['valid']);
        $this->assertSame(1, $row['flagged']);
        $this->assertSame('Flagged via CSV - no automatic issue detected', $row['notes']);
    }

    public function test_auto_flag_wins_over_csv_flagged_zero_and_keeps_meter_replacement_note(): void
    {
        $service = app(ReadingService::class);
        $user = User::factory()->create();
        $connection = ServiceConnection::factory()->create();

        MeterReading::factory()->create([
            'service_connection_id' => $connection->id,
            'present_reading' => 100.00,
            'previous_reading' => 90.00,
            'entered_at' => '2026-07-01',
            'flagged' => false,
        ]);

        $results = $service->prepareImportRows([
            [
                'account_number' => $connection->account_number,
                'present_reading' => '50.00',
                'reading_date' => '2026-07-31',
                'flagged' => '0',
            ],
        ], $user);

        $row = $results->first();

        $this->assertTrue($row['valid']);
        $this->assertSame(2, $row['flagged']);
        $this->assertSame('Present reading is lower than previous (meter may have been replaced)', $row['notes']);
    }

    public function test_no_flagged_column_still_auto_detects_low_reading(): void
    {
        $service = app(ReadingService::class);
        $user = User::factory()->create();
        $connection = ServiceConnection::factory()->create();

        MeterReading::factory()->create([
            'service_connection_id' => $connection->id,
            'present_reading' => 100.00,
            'previous_reading' => 90.00,
            'entered_at' => '2026-07-01',
            'flagged' => false,
        ]);

        $results = $service->prepareImportRows([
            [
                'account_number' => $connection->account_number,
                'present_reading' => '50.00',
                'reading_date' => '2026-07-31',
            ],
        ], $user);

        $row = $results->first();

        $this->assertTrue($row['valid']);
        $this->assertSame(2, $row['flagged']);
        $this->assertSame('Present reading is lower than previous (meter may have been replaced)', $row['notes']);
    }

    public function test_create_from_array_recomputes_previous_and_forces_level_2_for_in_file_order(): void
    {
        $service = app(ReadingService::class);
        $user = User::factory()->create();
        $connection = ServiceConnection::factory()->create();

        $service->createFromArray([
            'service_connection_id' => $connection->id,
            'present_reading' => 75.00,
            'previous_reading' => 0.00,
            'entered_at' => '2026-07-30',
            'flagged' => 1,
        ], $user, 'csv_import');

        $second = $service->createFromArray([
            'service_connection_id' => $connection->id,
            'present_reading' => 25.00,
            'previous_reading' => 0.00,
            'entered_at' => '2026-07-31',
            'flagged' => 0,
        ], $user, 'csv_import');

        $this->assertSame('75.00', (string) $second->previous_reading);
        $this->assertSame('-50.00', (string) $second->cu_m_used);
        $this->assertSame(2, $second->flagged);
    }

    public function test_create_from_array_preserves_csv_level_1_when_present_not_lower(): void
    {
        $service = app(ReadingService::class);
        $user = User::factory()->create();
        $connection = ServiceConnection::factory()->create();

        $reading = $service->createFromArray([
            'service_connection_id' => $connection->id,
            'present_reading' => 100.00,
            'previous_reading' => 0.00,
            'entered_at' => '2026-07-30',
            'flagged' => 1,
        ], $user, 'csv_import');

        $this->assertSame('0.00', (string) $reading->previous_reading);
        $this->assertSame(1, $reading->flagged);
    }

    public function test_create_from_array_keeps_zero_when_present_not_lower(): void
    {
        $service = app(ReadingService::class);
        $user = User::factory()->create();
        $connection = ServiceConnection::factory()->create();

        $reading = $service->createFromArray([
            'service_connection_id' => $connection->id,
            'present_reading' => 100.00,
            'previous_reading' => 0.00,
            'entered_at' => '2026-07-30',
            'flagged' => 0,
        ], $user, 'csv_import');

        $this->assertSame(0, $reading->flagged);
    }

    public function test_validate_reading_duplicate_detects_existing_date(): void
    {
        $service = app(ReadingService::class);
        $connection = ServiceConnection::factory()->create();

        MeterReading::factory()->create([
            'service_connection_id' => $connection->id,
            'entered_at' => '2026-07-15',
        ]);

        $this->assertSame(
            ['A reading for this connection already exists on this date.'],
            $service->validateReadingDuplicate($connection->id, '2026-07-15')
        );
    }

    public function test_validate_reading_duplicate_passes_for_new_date(): void
    {
        $service = app(ReadingService::class);
        $connection = ServiceConnection::factory()->create();

        MeterReading::factory()->create([
            'service_connection_id' => $connection->id,
            'entered_at' => '2026-07-15',
        ]);

        $this->assertSame([], $service->validateReadingDuplicate($connection->id, '2026-07-16'));
    }

    public function test_validate_reading_duplicate_ignores_own_record_on_edit(): void
    {
        $service = app(ReadingService::class);
        $connection = ServiceConnection::factory()->create();

        $reading = MeterReading::factory()->create([
            'service_connection_id' => $connection->id,
            'entered_at' => '2026-07-15',
        ]);

        $this->assertSame(
            [],
            $service->validateReadingDuplicate($connection->id, '2026-07-15', $reading->id)
        );
    }

    public function test_reading_before_previous_plus_30_days_is_invalid_in_import(): void
    {
        $service = app(ReadingService::class);
        $user = User::factory()->create();
        $connection = ServiceConnection::factory()->create();

        MeterReading::factory()->create([
            'service_connection_id' => $connection->id,
            'present_reading' => 100.00,
            'previous_reading' => 90.00,
            'entered_at' => '2026-07-10',
            'flagged' => false,
        ]);

        $results = $service->prepareImportRows([
            [
                'account_number' => $connection->account_number,
                'present_reading' => '120.00',
                'reading_date' => '2026-07-25',
            ],
        ], $user);

        $row = $results->first();

        $this->assertFalse($row['valid']);
        $this->assertStringContainsString('at least 30 days after the previous reading', $row['notes']);
    }
}

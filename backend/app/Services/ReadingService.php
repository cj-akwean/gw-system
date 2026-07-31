<?php

namespace App\Services;

use App\Models\MeterReading;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Collection;

class ReadingService
{
    public function computeUsage(float $present, float $previous): float
    {
        return round($present - $previous, 2);
    }

    public function getLatestReading(int $serviceConnectionId): ?MeterReading
    {
        return MeterReading::where('service_connection_id', $serviceConnectionId)
            ->latest('entered_at')
            ->first();
    }

    public function getPreviousReading(int $serviceConnectionId): float
    {
        $latest = $this->getLatestReading($serviceConnectionId);

        return $latest?->present_reading ?? 0.00;
    }

    public function validateReading(
        int $serviceConnectionId,
        float $present,
        ?float $previous = null,
        ?string $readingDate = null,
    ): array {
        $errors = [];
        $flagged = false;

        $connection = ServiceConnection::find($serviceConnectionId);

        if (! $connection) {
            return ['valid' => false, 'errors' => ['Service connection not found.'], 'flagged' => false];
        }

        if ($connection->status !== 'active') {
            $errors[] = "Service connection '{$connection->account_number}' is not active (status: {$connection->status}).";
        }

        if ($present < 0) {
            $errors[] = 'Present reading cannot be negative.';
        }

        $previous ??= $this->getPreviousReading($serviceConnectionId);

        if ($present < $previous) {
            $flagged = true;
        }

        if ($readingDate && strtotime($readingDate) > strtotime('today')) {
            $errors[] = 'Reading date cannot be in the future.';
        }

        $duplicate = MeterReading::where('service_connection_id', $serviceConnectionId)
            ->whereDate('entered_at', $readingDate ?? now())
            ->exists();

        if ($duplicate) {
            $errors[] = 'A reading for this connection already exists on this date.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'flagged' => $flagged,
        ];
    }

    public function createFromArray(
        array $data,
        User $enteredBy,
        string $method = 'manual',
    ): MeterReading {
        $previous = $data['previous_reading']
            ?? $this->getPreviousReading((int) $data['service_connection_id']);

        $present = (float) $data['present_reading'];
        $cuMUsed = $this->computeUsage($present, $previous);

        return MeterReading::create([
            'service_connection_id' => $data['service_connection_id'],
            'present_reading' => $present,
            'previous_reading' => $previous,
            'cu_m_used' => $cuMUsed,
            'entered_by' => $enteredBy->id,
            'entered_at' => $data['entered_at'] ?? now(),
            'method' => $method,
            'flagged' => $data['flagged'] ?? false,
        ]);
    }

    public function prepareImportRows(array $csvRows, User $importedBy): Collection
    {
        $results = collect();
        $seenPairs = [];

        foreach ($csvRows as $index => $row) {
            $rowIndex = $index + 2;
            $connection = $this->resolveConnection($row);

            if (! $connection) {
                $results->push([
                    'row' => $rowIndex,
                    'valid' => false,
                    'errors' => ['No matching service connection found.'],
                    'data' => [
                        'present_reading' => (float) ($row['present_reading'] ?? 0),
                        'previous_reading' => 0.00,
                        'entered_at' => $row['reading_date'] ?? null,
                        'flagged' => false,
                    ],
                    'connection' => null,
                ]);
                continue;
            }

            $present = (float) ($row['present_reading'] ?? -1);
            $readingDate = $row['reading_date'] ?? null;
            $previous = $this->getPreviousReading($connection->id);

            $dateKey = $readingDate
                ? now()->parse($readingDate)->format('Y-m-d')
                : now()->format('Y-m-d');

            $pairKey = $connection->id.'|'.$dateKey;

            if (isset($seenPairs[$pairKey])) {
                $results->push([
                    'row' => $rowIndex,
                    'valid' => false,
                    'errors' => [
                        "Duplicate row within this file: connection '{$connection->account_number}' already has a reading on {$dateKey} (row {$seenPairs[$pairKey]}).",
                    ],
                    'data' => [
                        'present_reading' => $present,
                        'previous_reading' => $previous,
                        'entered_at' => $readingDate ? now()->parse($readingDate) : now(),
                        'flagged' => false,
                    ],
                    'connection' => $connection,
                ]);
                continue;
            }

            $seenPairs[$pairKey] = $rowIndex;

            $validation = $this->validateReading(
                $connection->id,
                $present,
                $previous,
                $readingDate,
            );

            $results->push([
                'row' => $rowIndex,
                'valid' => $validation['valid'],
                'flagged' => $validation['flagged'],
                'errors' => $validation['errors'],
                'data' => [
                    'service_connection_id' => $connection->id,
                    'present_reading' => $present,
                    'previous_reading' => $previous,
                    'entered_at' => $readingDate ? now()->parse($readingDate) : now(),
                    'flagged' => $validation['flagged'],
                ],
                'connection' => $connection,
            ]);
        }

        return $results;
    }

    public function resolveConnection(array $row): ?ServiceConnection
    {
        if (! empty($row['account_number'])) {
            return ServiceConnection::where('account_number', $row['account_number'])
                ->first();
        }

        if (! empty($row['meter_number'])) {
            return ServiceConnection::where('meter_number', $row['meter_number'])
                ->first();
        }

        return null;
    }

    public function validateHeaders(array $row): array
    {
        $errors = [];
        $keys = array_map('strtolower', array_keys($row));

        if (! in_array('present_reading', $keys)) {
            $errors[] = 'Missing required column: present_reading.';
        }

        if (! in_array('account_number', $keys) && ! in_array('meter_number', $keys)) {
            $errors[] = 'Missing required column: account_number or meter_number.';
        }

        return $errors;
    }
}

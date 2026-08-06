<?php

namespace App\Exports;

use App\Models\ServiceConnection;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ServiceConnectionsExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(protected Builder $query)
    {
    }

    public function query(): Builder
    {
        return $this->query
            ->clone()
            ->with(['barangay', 'rateSchedule'])
            ->orderBy('account_number');
    }

    public function headings(): array
    {
        return [
            'account_number',
            'meter_number',
            'name',
            'barangay',
            'address',
            'status',
            'connection_date',
            'rate_schedule',
            'pending_balance',
            'created_at',
        ];
    }

    public function map($connection): array
    {
        /** @var ServiceConnection $connection */
        return [
            $this->sanitize((string) $connection->account_number),
            $this->sanitize((string) $connection->meter_number),
            $this->sanitize((string) $connection->registered_name),
            $this->sanitize((string) ($connection->barangay?->name ?? '')),
            $this->sanitize((string) $connection->address),
            $this->sanitize((string) $connection->status),
            $connection->connection_date?->toDateString(),
            $this->sanitize((string) ($connection->rateSchedule?->name ?? '')),
            number_format((float) ($connection->pending_balance ?? 0), 2, '.', ''),
            $connection->created_at?->toDateTimeString(),
        ];
    }

    private function sanitize(string $value): string
    {
        if (
            $value !== ''
            && in_array($value[0], ["=", "+", "-", "@", "\t", "\r"], true)
        ) {
            return "'".$value;
        }

        return $value;
    }
}
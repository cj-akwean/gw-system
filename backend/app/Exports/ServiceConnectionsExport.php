<?php

namespace App\Exports;

use App\Exports\Concerns\SanitizesCsvFields;
use App\Models\ServiceConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ServiceConnectionsExport implements FromQuery, WithHeadings, WithMapping
{
    use SanitizesCsvFields;

    public function __construct(protected Builder $query) {}

    public function query(): Builder
    {
        $query = $this->query
            ->clone()
            ->with(['barangay', 'rateSchedule'])
            ->orderBy('account_number');

        if (! $this->querySelectsPendingBalance()) {
            $query->withPendingBalance();
        }

        return $query;
    }

    public function headings(): array
    {
        return [
            'account_number',
            'meter_number',
            'name',
            'barangay',
            'address',
            'phone',
            'email',
            'gender',
            'birthdate',
            'civil_status',
            'occupation',
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
            $this->sanitize((string) ($connection->phone ?? '')),
            $this->sanitize((string) ($connection->email ?? '')),
            $this->sanitize((string) ($connection->gender ?? '')),
            $connection->birthdate?->toDateString() ?? '',
            $this->sanitize((string) ($connection->civil_status ?? '')),
            $this->sanitize((string) ($connection->occupation ?? '')),
            $this->sanitize((string) $connection->status),
            $connection->connection_date?->toDateString(),
            $this->sanitize((string) ($connection->rateSchedule?->name ?? '')),
            number_format((float) ($connection->pending_balance ?? 0), 2, '.', ''),
            $connection->created_at?->toDateTimeString(),
        ];
    }

    private function querySelectsPendingBalance(): bool
    {
        $columns = $this->query->getQuery()->columns ?? [];

        foreach ($columns as $column) {
            if (str_contains($column instanceof Expression ? $column->getValue($this->query->getQuery()->getGrammar()) : (string) $column, 'pending_balance')) {
                return true;
            }
        }

        return false;
    }
}

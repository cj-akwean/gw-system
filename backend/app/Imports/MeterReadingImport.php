<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class MeterReadingImport implements ToArray, WithHeadingRow
{
    public function array(array $rows): array
    {
        return $rows;
    }

    public function headingRow(): int
    {
        return 1;
    }
}

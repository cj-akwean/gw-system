<?php

namespace App\Exports\Concerns;

trait SanitizesCsvFields
{
    private function sanitize(string $value): string
    {
        if (
            $value !== ''
            && in_array($value[0], ['=', '+', '-', '@', "\0", "\t", "\r", "\n"], true)
        ) {
            return "'".$value;
        }

        return $value;
    }
}
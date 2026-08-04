<?php

namespace App\Exceptions;

use App\Models\Invoice;
use RuntimeException;

class InvoiceNotPayableException extends RuntimeException
{
    public function __construct(Invoice $invoice)
    {
        parent::__construct(sprintf(
            'Invoice %s (status: %s) is not payable.',
            $invoice->id,
            $invoice->status
        ));
    }
}

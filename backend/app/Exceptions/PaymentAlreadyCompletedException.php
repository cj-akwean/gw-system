<?php

namespace App\Exceptions;

use App\Models\Invoice;
use RuntimeException;

/**
 * Thrown when an invoice's stored PayMongo payment intent has already
 * succeeded but the invoice has not been credited (the payment.paid webhook
 * was missed or is still in flight). The customer's money has moved, so a new
 * payment must NOT be created — only the reconciliation flow may credit it.
 */
class PaymentAlreadyCompletedException extends RuntimeException
{
    public function __construct(Invoice $invoice)
    {
        parent::__construct(sprintf(
            'Payment for invoice %s already succeeded but the invoice is not credited.',
            $invoice->id
        ));
    }
}

<?php

namespace App\Mail;

use App\Filament\Resources\PaymentResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Payment confirmation email sent to the customer(s) linked to an invoice's
 * connection once a PayMongo payment is confirmed. The itemized invoice is
 * attached as a PDF generated in-memory (no permanent file storage).
 */
class PaymentConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public Payment $payment,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Payment received — Invoice '.$this->invoice->invoice_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.payment-confirmation-html',
            text: 'emails.payment-confirmation-text',
            with: [
                'paymentMethodLabel' => PaymentResource::methodLabel($this->payment->method, $this->payment->paymongo_source),
            ],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn (): string => app(PdfService::class)->generate($this->invoice, $this->payment),
                'invoice-'.$this->invoice->invoice_number.'.pdf',
            )->withMime('application/pdf'),
        ];
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\PdfService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BillingPdfCommand extends Command
{
    protected $signature = 'billing:pdf {invoice-number} {--output= : Write the PDF to this path (default: storage disk pdf-verification/<invoice-number>.pdf)}';

    protected $description = 'Generate the itemized invoice PDF for a given invoice number';

    public function handle(PdfService $pdf): int
    {
        $invoiceNumber = (string) $this->argument('invoice-number');

        $invoice = Invoice::where('invoice_number', $invoiceNumber)
            ->with('serviceConnection')
            ->first();

        if (! $invoice) {
            $this->error("Invoice not found: {$invoiceNumber}");

            return self::FAILURE;
        }

        $contents = $pdf->generate($invoice);

        $output = (string) $this->option('output');
        $target = $output !== '' ? $output : 'pdf-verification/'.$invoice->invoice_number.'.pdf';

        $disk = Storage::disk(config('filesystems.default'));
        $disk->put($target, $contents);

        $this->info("Wrote {$invoice->invoice_number} PDF to: ".$disk->path($target));

        return self::SUCCESS;
    }
}

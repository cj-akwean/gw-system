<?php

namespace App\Filament\Resources\PaymentResource\Pages;

use App\Filament\Resources\PaymentResource;
use App\Services\PdfService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadReceipt')
                ->label('Download receipt PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->action(function () {
                    $payment = $this->getRecord();
                    $invoice = $payment->invoice;

                    if ($invoice === null) {
                        Notification::make()
                            ->title('Receipt unavailable')
                            ->body('This payment has no linked invoice to render a receipt from.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $pdf = app(PdfService::class)->generate($invoice, $payment);

                    // Filament actions only stream BinaryFileResponse
                    // downloads; a plain dompdf Response cannot be serialized
                    // through Livewire. Write to a temp file and stream that
                    // instead (same pattern as the financial report PDF).
                    $tempPath = tempnam(sys_get_temp_dir(), 'gws-receipt-').'.pdf';
                    file_put_contents($tempPath, $pdf);

                    $filename = 'receipt-'.$invoice->invoice_number.'.pdf';

                    return response()->download($tempPath, $filename)
                        ->deleteFileAfterSend(true);
                }),
        ];
    }
}
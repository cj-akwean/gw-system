<?php

namespace App\Filament\Resources\BillingRunResource\Pages;

use App\Filament\Resources\BillingRunResource;
use App\Models\BillingRun;
use App\Services\BillingRunService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListBillingRuns extends ListRecords
{
    protected static string $resource = BillingRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runBilling')
                ->label('Run Billing')
                ->icon('heroicon-o-bolt')
                ->color('primary')
                ->modalHeading('Run monthly billing')
                ->modalDescription('Creates a billing run and dispatches the queue job. The row below flips to completed/failed once the queue worker processes it.')
                ->modalSubmitActionLabel('Start Billing Run')
                ->form([
                    DatePicker::make('period')
                        ->label('Billing Period End')
                        ->default(date('Y-m-d', strtotime('last day of previous month')))
                        ->helperText('End of the month to bill. Defaults to the previous calendar month.'),

                    Checkbox::make('force')
                        ->label('Force — abandon a stale run in progress')
                        ->helperText('Only needed if a run for this period has been stuck as "running" for more than '.BillingRun::STALE_AFTER.'.'),
                ])
                ->action(function (array $data): void {
                    $result = app(BillingRunService::class)->start(
                        periodEnd: $data['period'] ?? null,
                        force: $data['force'] ?? false,
                        startedByUserId: Filament::auth()->id(),
                    );

                    if ($result['error'] !== null) {
                        Notification::make()
                            ->title('Billing run blocked')
                            ->body($result['error'])
                            ->danger()
                            ->send();

                        return;
                    }

                    $run = $result['run'];

                    Notification::make()
                        ->title('Billing run dispatched')
                        ->body("Run #{$run->id} for {$run->period_end->toDateString()} queued. Results appear once the queue worker processes it.")
                        ->success()
                        ->send();

                    $this->resetTable();
                }),
        ];
    }
}

<?php

use App\Http\Controllers\Admin\ResendReceiptController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['web', 'auth:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/payments/{payment}/resend-receipt', ResendReceiptController::class)
            ->middleware('throttle:10,1')
            ->name('payments.resend-receipt');
    });

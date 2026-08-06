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
            ->name('payments.resend-receipt');
    });

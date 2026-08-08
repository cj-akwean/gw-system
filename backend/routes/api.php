<?php

use App\Http\Controllers\Api\ConnectionLinkController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\InvoicePaymentController;
use App\Http\Controllers\Api\PayMongoWebhookController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\SavedPaymentMethodController;
use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Each inline throttle carries a distinct $prefix so route buckets never bleed
// into each other. Anonymous keys are derived from the client IP alone
// (domain|ip) — without a prefix, /login and /paymongo/webhook would share ONE
// per-IP counter and a burst on one would 429 the other. Authenticated keys are
// per-user; the prefix keeps each route's per-user budget separate too.
Route::post('/paymongo/webhook', [PayMongoWebhookController::class, 'store'])
    ->middleware('throttle:60,1,paymongo-webhook');

Route::post('/login', [AuthController::class, 'login'])
    ->middleware(['guest', 'throttle:10,1,auth-login'])
    ->name('login');
Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware(['auth:sanctum', 'throttle:30,1,auth-logout']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware(['auth:sanctum', 'throttle:30,1,user-index']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/links', [ConnectionLinkController::class, 'index'])->middleware('throttle:30,1,links-index');
    Route::post('/links', [ConnectionLinkController::class, 'store'])->middleware('throttle:30,1,links-store');
    Route::delete('/links/{link}', [ConnectionLinkController::class, 'destroy'])->middleware('throttle:30,1,links-destroy');
    Route::get('/invoices', [InvoiceController::class, 'index'])->middleware('throttle:30,1,invoices-index');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('throttle:30,1,invoices-index');
    Route::post('/invoices/{invoice}/pay', [InvoicePaymentController::class, 'store'])
        ->middleware('throttle:20,1,invoices-pay');
    Route::post('/invoices/{invoice}/pay-with-saved', [InvoicePaymentController::class, 'payWithSaved'])
        ->middleware('throttle:20,1,invoices-pay-saved');
    Route::get('/saved-payment-methods', [SavedPaymentMethodController::class, 'index'])
        ->middleware('throttle:30,1,saved-payment-methods-index');
    Route::delete('/saved-payment-methods/{savedPaymentMethod}', [SavedPaymentMethodController::class, 'destroy'])
        ->middleware('throttle:20,1,saved-payment-methods-destroy');
    Route::get('/payments', [PaymentController::class, 'index'])->middleware('throttle:30,1,payments-index');
    Route::post('/payments/intent-status', [PaymentController::class, 'intentStatus'])
        ->middleware('throttle:30,1,payments-intent-status');
});

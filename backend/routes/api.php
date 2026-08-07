<?php

use App\Http\Controllers\Api\ConnectionLinkController;
use App\Http\Controllers\Api\InvoicePaymentController;
use App\Http\Controllers\Api\PayMongoWebhookController;
use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/paymongo/webhook', [PayMongoWebhookController::class, 'store'])
    ->middleware('throttle:60,1');

Route::post('/login', [AuthController::class, 'login'])
    ->middleware(['guest', 'throttle:10,1'])
    ->name('login');
Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware(['auth:sanctum', 'throttle:30,1']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware(['auth:sanctum', 'throttle:30,1']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/links', [ConnectionLinkController::class, 'index'])->middleware('throttle:30,1');
    Route::post('/links', [ConnectionLinkController::class, 'store'])->middleware('throttle:30,1');
    Route::delete('/links/{link}', [ConnectionLinkController::class, 'destroy'])->middleware('throttle:30,1');
    Route::post('/invoices/{invoice}/pay', [InvoicePaymentController::class, 'store'])
        ->middleware('throttle:20,1');
});

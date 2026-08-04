<?php

use App\Http\Controllers\Api\ConnectionLinkController;
use App\Http\Controllers\Api\InvoicePaymentController;
use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])
    ->middleware(['guest', 'throttle:10,1'])
    ->name('login');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/links', [ConnectionLinkController::class, 'index']);
    Route::post('/links', [ConnectionLinkController::class, 'store']);
    Route::delete('/links/{link}', [ConnectionLinkController::class, 'destroy']);
    Route::post('/invoices/{invoice}/pay', [InvoicePaymentController::class, 'store'])
        ->middleware('throttle:20,1');
});

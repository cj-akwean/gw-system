<?php

use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware(['guest', 'throttle:10,1']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/links', [App\Http\Controllers\Api\ConnectionLinkController::class, 'index']);
    Route::post('/links', [App\Http\Controllers\Api\ConnectionLinkController::class, 'store']);
    Route::delete('/links/{link}', [App\Http\Controllers\Api\ConnectionLinkController::class, 'destroy']);
});

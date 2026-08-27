<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\TransferController;
use Illuminate\Support\Facades\Route;

// Public
Route::post('/accounts', [AccountController::class, 'store']);
Route::post('/login', [AuthController::class, 'login']);

// Protected — require a valid Sanctum token
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/accounts/{id}', [AccountController::class, 'show']);
    Route::get('/accounts/{id}/transactions', [AccountController::class, 'transactions']);

    // Money-moving endpoints: require token AND idempotency
    Route::middleware('idempotency')->group(function () {
        Route::post('/accounts/{id}/deposits', [AccountController::class, 'deposit']);
        Route::post('/accounts/{id}/withdrawals', [AccountController::class, 'withdraw']);
        Route::post('/transfers', [TransferController::class, 'store']);
    });
});

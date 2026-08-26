<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\TransferController;
use Illuminate\Support\Facades\Route;

Route::post('/accounts', [AccountController::class, 'store']);
Route::get('/accounts/{id}', [AccountController::class, 'show']);
Route::get('/accounts/{id}/transactions', [AccountController::class, 'transactions']);

// Money-moving endpoints require an Idempotency-Key
Route::middleware('idempotency')->group(function () {
    Route::post('/accounts/{id}/deposits', [AccountController::class, 'deposit']);
    Route::post('/accounts/{id}/withdrawals', [AccountController::class, 'withdraw']);
    Route::post('/transfers', [TransferController::class, 'store']);
});

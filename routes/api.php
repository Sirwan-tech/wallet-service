<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\TransferController;
use Illuminate\Support\Facades\Route;

Route::post('/accounts', [AccountController::class, 'store']);
Route::get('/accounts/{id}', [AccountController::class, 'show']);
Route::post('/accounts/{id}/deposits', [AccountController::class, 'deposit']);
Route::post('/accounts/{id}/withdrawals', [AccountController::class, 'withdraw']);
Route::get('/accounts/{id}/transactions', [AccountController::class, 'transactions']);

Route::post('/transfers', [TransferController::class, 'store']);

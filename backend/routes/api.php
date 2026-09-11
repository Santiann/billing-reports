<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\BillingReportController;
use App\Http\Controllers\Api\CustomerController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('customers', CustomerController::class)
        ->only(['index', 'store', 'show', 'update']);

    Route::apiResource('billings', BillingController::class)
        ->only(['index', 'store', 'show', 'update']);

    Route::post('billings/{billing}/payment', [BillingController::class, 'pay'])
        ->name('billings.pay');

    Route::get('reports/billings', BillingReportController::class)
        ->name('reports.billings');
});

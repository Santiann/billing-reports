<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\BillingReportController;
use App\Http\Controllers\Api\BillingReportCsvController;
use App\Http\Controllers\Api\BillingReportPdfController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerImportController;
use App\Http\Controllers\Api\DashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::apiResource('customers', CustomerController::class)
        ->only(['index', 'store', 'show', 'update']);

    Route::post('customers/import', CustomerImportController::class)
        ->name('customers.import');

    Route::apiResource('billings', BillingController::class)
        ->only(['index', 'store', 'show', 'update']);

    Route::post('billings/{billing}/payment', [BillingController::class, 'pay'])
        ->name('billings.pay');

    Route::get('reports/billings', BillingReportController::class)
        ->name('reports.billings');

    Route::get('reports/billings/csv', BillingReportCsvController::class)
        ->name('reports.billings.csv');

    Route::get('reports/billings/pdf', BillingReportPdfController::class)
        ->name('reports.billings.pdf');
});

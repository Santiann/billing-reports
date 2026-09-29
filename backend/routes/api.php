<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\BillingImportController;
use App\Http\Controllers\Api\BillingReportController;
use App\Http\Controllers\Api\BillingReportCsvController;
use App\Http\Controllers\Api\BillingReportPdfController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerImportController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

/*
 * Health for monitoring: public, because a probe does not log in.
 *
 * Outside any authenticated group on purpose. Laravel's `/up` still exists and only answers
 * "PHP came up"; this route answers whether the dependencies answer.
 */
Route::get('health', HealthController::class)->name('health');

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

/*
 * Reading: every authenticated role.
 *
 * Exporting is here on purpose — the file is the same report in another format, and refusing
 * it to someone who can see the screen would be protecting the data from the wrong place.
 */
Route::middleware('auth:sanctum')->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::apiResource('customers', CustomerController::class)
        ->only(['index', 'show']);

    Route::apiResource('billings', BillingController::class)
        ->only(['index', 'show']);

    Route::get('billings/{billing}/audit', [BillingController::class, 'audit'])
        ->name('billings.audit');

    Route::get('reports/billings', BillingReportController::class)
        ->name('reports.billings');

    Route::get('reports/billings/csv', BillingReportCsvController::class)
        ->name('reports.billings.csv');

    Route::get('reports/billings/pdf', BillingReportPdfController::class)
        ->name('reports.billings.pdf');
});

/*
 * Writing: the administrator role only.
 *
 * The group exists so the list of routes that write fits in one glance. A new route outside
 * it jumps out in review — and, if it slips through, RoleAccessTest does not cover it, which
 * is the next signal.
 */
Route::middleware(['auth:sanctum', 'can.write'])->group(function () {
    Route::apiResource('customers', CustomerController::class)
        ->only(['store', 'update']);

    Route::post('customers/import', CustomerImportController::class)
        ->name('customers.import');

    Route::apiResource('billings', BillingController::class)
        ->only(['store', 'update']);

    Route::post('billings/import', BillingImportController::class)
        ->name('billings.import');

    /*
     * The two idempotent routes, and they are the ones that need to be.
     *
     * On these, repeating moves money around: a double click on the payment charges twice,
     * and a late reversal retry — after the billing has been paid again — undoes a payment
     * nobody asked to undo. On the others the damage from repeating is smaller or
     * non-existent: creating two customers with the same document hits the unique index, and
     * the CSV import already answers with a report of what it wrote.
     */
    Route::post('billings/{billing}/payment', [BillingController::class, 'pay'])
        ->middleware('idempotent')
        ->name('billings.pay');

    Route::post('billings/{billing}/reversal', [BillingController::class, 'reverse'])
        ->middleware('idempotent')
        ->name('billings.reverse');
});

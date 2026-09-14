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
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

/*
 * Leitura: todo perfil autenticado.
 *
 * Exportar está aqui de propósito — o arquivo é o mesmo relatório em outro
 * formato, e recusá-lo a quem pode ver a tela seria proteger o dado do lugar
 * errado.
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
 * Escrita: só o perfil de administrador.
 *
 * O grupo existe para a lista de rotas que escrevem caber numa olhada. Rota
 * nova fora daqui salta aos olhos na revisão — e, se escapar, RoleAccessTest
 * não a cobre, que é o sinal seguinte.
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
     * A única rota idempotente, e é a que precisa ser.
     *
     * Duplo clique e retry de rede aqui cobram duas vezes. Nas outras, o
     * estrago de repetir é menor ou inexistente: criar dois clientes com o
     * mesmo documento esbarra no índice único, e a importação de CSV já
     * responde o relatório do que gravou.
     */
    Route::post('billings/{billing}/payment', [BillingController::class, 'pay'])
        ->middleware('idempotent')
        ->name('billings.pay');
});

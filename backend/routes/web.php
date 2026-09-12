<?php

use App\Http\Controllers\DocumentationController;
use Illuminate\Support\Facades\Route;

/*
 * A raiz do backend é a documentação da API, não a welcome do Laravel.
 *
 * Quem abre localhost:8000 está procurando a API. Entregar a página de boas
 * vindas do framework é desperdiçar a única URL que a pessoa já sabe de cor.
 */
Route::get('/', [DocumentationController::class, 'page']);

/*
 * A spec crua, para importar em Postman, Insomnia ou num gerador de cliente.
 */
Route::get('/openapi.yaml', [DocumentationController::class, 'raw']);

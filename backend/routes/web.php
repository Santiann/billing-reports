<?php

use App\Http\Controllers\DocumentationController;
use Illuminate\Support\Facades\Route;

/*
 * The backend's root is the API documentation, not Laravel's welcome page.
 *
 * Whoever opens localhost:8000 is looking for the API. Serving the framework's welcome page
 * wastes the one URL the person already knows by heart.
 */
Route::get('/', [DocumentationController::class, 'page']);

/*
 * The raw spec, to import into Postman, Insomnia or a client generator.
 */
Route::get('/openapi.yaml', [DocumentationController::class, 'raw']);

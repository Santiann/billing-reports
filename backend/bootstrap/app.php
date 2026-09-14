<?php

use App\Http\Middleware\EnsureUserCanWrite;
use App\Http\Middleware\IdempotentRequest;
use App\Http\Middleware\RequestId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Primeiro de todos: o identificador precisa existir antes de qualquer
        // linha de log da requisição, inclusive as de erro.
        $middleware->api(prepend: [RequestId::class]);

        $middleware->alias([
            'can.write' => EnsureUserCanWrite::class,
            'idempotent' => IdempotentRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

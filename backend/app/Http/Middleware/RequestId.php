<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Um identificador por requisição, na resposta e no log.
 *
 * Preserva o que vem de fora. O nginx já gera um `X-Request-Id` e o imprime no
 * log de acesso; sobrescrever aqui cortaria a ligação entre a linha da borda e
 * as linhas da aplicação. O valor volta no cabeçalho da resposta para que um
 * relato de problema possa citá-lo.
 */
class RequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $id = trim((string) $request->headers->get(self::HEADER)) ?: (string) Str::uuid();

        $request->headers->set(self::HEADER, $id);

        $resposta = $next($request);
        $resposta->headers->set(self::HEADER, $id);

        return $resposta;
    }
}

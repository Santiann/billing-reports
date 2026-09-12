<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Barra a escrita para quem tem perfil de consulta.
 *
 * Middleware e não Policy, e a escolha tem motivo: Policy resolve autorização
 * POR REGISTRO — "este usuário pode editar ESTA cobrança". A regra aqui é por
 * PERFIL e vale para todo registro, então amarrá-la ao grupo de rotas deixa a
 * lista de endpoints protegidos visível num arquivo só, em vez de espalhada
 * por uma classe de política para cada model.
 *
 * O ganho prático é o `routes/api.php`: dá para ler quais rotas escrevem
 * olhando o arquivo, e uma rota nova fora do grupo salta aos olhos.
 */
class EnsureUserCanWrite
{
    public function handle(Request $request, Closure $next): Response
    {
        // A ausência de usuário é problema do `auth:sanctum`, que roda antes e
        // responde 401. Chegar aqui sem usuário significaria middleware fora de
        // ordem, e responder 403 esconderia esse erro.
        $usuario = $request->user();

        if ($usuario !== null && ! $usuario->role->canWrite()) {
            return response()->json([
                'message' => 'Seu perfil é de consulta e não permite esta operação.',
            ], 403);
        }

        return $next($request);
    }
}

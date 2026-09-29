<?php

namespace App\Http\Middleware;

use App\Domain\Idempotency\IdempotencyOutcome;
use App\Domain\Idempotency\IdempotencyStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Torna a requisição repetível quando ela traz `Idempotency-Key`.
 *
 * Middleware, e não código no controller, por um motivo que se vê no teste: a
 * resposta de validação precisa ser repetida também, e o 422 nasce no
 * FormRequest, antes de o controller existir. Só daqui de fora dá para guardar
 * o que a rota respondeu, independente de quem respondeu.
 *
 * O cabeçalho é opcional. Sem ele nada muda — inclusive a recusa de pagar uma
 * cobrança já paga, que continua 422. Idempotência serve a quem REPETE a mesma
 * operação; transformar a segunda tentativa em sucesso para todo mundo
 * esconderia um erro de verdade.
 */
class IdempotentRequest
{
    public function __construct(private readonly IdempotencyStore $store) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header(IdempotencyStore::HEADER, ''));
        $user = $request->user();

        // Sem chave, ou sem usuário — este segundo caso é do `auth`, que roda
        // antes e responde 401; chegar aqui sem usuário seria middleware fora
        // de ordem, e inventar uma resposta aqui esconderia isso.
        if ($key === '' || $user === null) {
            return $next($request);
        }

        if (mb_strlen($key) > 255) {
            return $this->refuse(
                'A chave de idempotência passa de 255 caracteres.',
                422,
            );
        }

        $fingerprint = $this->fingerprint($request);
        $reserve = $this->store->reserve($user->id, $key, $fingerprint);

        return match ($reserve->outcome) {
            IdempotencyOutcome::Reserved => $this->process($request, $next, $user->id, $key, $fingerprint),

            IdempotencyOutcome::Replayed => $this->replay($reserve->status, $reserve->body),

            IdempotencyOutcome::Conflict => $this->refuse(
                'Esta chave de idempotência já foi usada para outra requisição.',
                422,
            ),

            IdempotencyOutcome::InFlight => $this->refuse(
                'Uma requisição com esta chave de idempotência ainda está em andamento.',
                409,
            ),
        };
    }

    private function process(Request $request, Closure $next, int $userId, string $key, string $fingerprint): Response
    {
        $response = $next($request);

        /*
         * Erro de servidor devolve a chave.
         *
         * Um 500 não é resultado da operação, é falha em produzi-lo — e o certo
         * depois de um 500 é tentar de novo. Guardá-lo faria a chave repetir a
         * falha pelas 24 horas seguintes, que é o oposto do que ela existe para
         * fazer.
         */
        if ($response->getStatusCode() >= 500) {
            $this->store->release($userId, $key);

            return $response;
        }

        /*
         * Resposta em stream não tem corpo para guardar: o conteúdo só existe
         * enquanto é enviado, e lê-lo aqui anularia o streaming. Nenhuma rota
         * idempotente exporta arquivo hoje; a guarda existe para o dia em que
         * alguém aplicar este middleware numa que exporte.
         */
        if ($response->getContent() === false) {
            $this->store->release($userId, $key);

            return $response;
        }

        $this->store->store(
            $userId,
            $key,
            $fingerprint,
            $response->getStatusCode(),
            $response->getContent(),
        );

        return $response;
    }

    /**
     * A resposta guardada, devolvida como veio.
     *
     * O cabeçalho `Idempotent-Replay` avisa que é repetição. Quem chama não
     * precisa dele para funcionar — o corpo é idêntico —, mas precisa para
     * distinguir "pagou agora" de "já tinha pago" no log.
     */
    private function replay(int $status, string $body): Response
    {
        return response($body, $status)
            ->header('Content-Type', 'application/json')
            ->header('Idempotent-Replay', 'true');
    }

    private function refuse(string $message, int $status): Response
    {
        return response()->json(['message' => $message], $status);
    }

    /**
     * O que identifica o PEDIDO, para separar repetição de reaproveitamento.
     *
     * Entram o método, o caminho — com o id da cobrança dentro dele, então a
     * mesma chave em outra cobrança é outro pedido — e os dados enviados,
     * ordenados para que a ordem das chaves do JSON não mude a impressão.
     */
    private function fingerprint(Request $request): string
    {
        return hash('sha256', (string) json_encode([
            $request->method(),
            $request->path(),
            $this->sort($request->all()),
        ]));
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function sort(array $data): array
    {
        ksort($data);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sort($value);
            }
        }

        return $data;
    }
}

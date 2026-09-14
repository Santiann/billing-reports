<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Health para monitoramento: pública, barata e honesta.
 *
 * Honesta é a parte que importa. Um health que responde 200 sempre é pior que
 * nenhum, porque o monitoramento passa a confiar nele e para de avisar. Aqui,
 * dependência fora do ar derruba a resposta para 503 e diz qual delas.
 *
 * A checagem do cache é de LEITURA. Escrever provaria mais, e custaria um
 * commit por sonda — com o driver de banco, cada gravação vai ao disco. Um
 * monitoramento que consulta a cada dez segundos escreveria 8.640 vezes por
 * dia para responder uma pergunta que a leitura já responde: o driver está
 * acessível.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checagens = [
            'database' => $this->checar(fn () => DB::connection()->select('select 1')),
            'cache' => $this->checar(fn () => Cache::get('health')),
        ];

        $saudavel = ! in_array(false, array_column($checagens, 'ok'), true);

        return response()->json([
            'status' => $saudavel ? 'ok' : 'degraded',
            'checks' => $checagens,
        ], $saudavel ? 200 : 503);
    }

    /** @return array<string, mixed> */
    private function checar(Closure $checagem): array
    {
        $inicio = microtime(true);

        try {
            $checagem();
        } catch (Throwable $erro) {
            /*
             * A mensagem do driver fica no LOG, não na resposta.
             *
             * A rota é pública, e o erro do PDO nomeia host, porta e driver —
             * `SQLSTATE[HY000] [2002] Connection refused`, e o DSN junto. Isso
             * é reconhecimento gratuito para quem sonda. Quem precisa do
             * detalhe é quem opera, e tem o log estruturado com o identificador
             * da requisição para achá-lo.
             */
            Log::error('health.falhou', ['erro' => $erro->getMessage()]);

            return ['ok' => false, 'error' => 'não respondeu'];
        }

        return ['ok' => true, 'duration_ms' => round((microtime(true) - $inicio) * 1000, 2)];
    }
}

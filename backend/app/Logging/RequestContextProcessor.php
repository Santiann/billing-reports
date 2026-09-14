<?php

namespace App\Logging;

use App\Http\Middleware\RequestId;
use Illuminate\Support\Facades\Auth;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Põe em toda linha de log quem pediu, o que pediu e sob qual identificador.
 *
 * Processador, e não `Log::withContext()` num middleware, por causa do
 * `user_id`: middleware de grupo roda ANTES do `auth:sanctum`, então ali o
 * usuário ainda não existe e o campo sairia nulo em produção — enquanto no
 * teste, onde `actingAs` resolve o usuário mais cedo, pareceria funcionar. O
 * processador é avaliado no momento de cada linha, quando a autenticação já
 * aconteceu.
 *
 * `hasUser()` antes de `id()` de propósito: perguntar o id resolveria o guard
 * a partir do logger, o que inverteria a ordem das coisas. Linha registrada
 * antes da autenticação — uma tentativa de login falha, por exemplo — sai sem
 * usuário, que é a verdade.
 */
final class RequestContextProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $contexto = ['user_id' => Auth::hasUser() ? Auth::id() : null];

        $request = request();
        $identificador = $request->headers->get(RequestId::HEADER);

        /*
         * A presença do cabeçalho é o que diz que houve requisição HTTP.
         *
         * `runningInConsole()` parecia a pergunta certa e não é: a suíte roda
         * pelo artisan, então em console o teste nunca veria o contexto que a
         * produção veria. E em comando de verdade o container ainda expõe um
         * `Request` sintético, com método GET e caminho "/", que seria ruído —
         * mas sem este cabeçalho, porque quem o põe é o middleware.
         */
        if ($identificador !== null) {
            $contexto += [
                'request_id' => $identificador,
                'method' => $request->method(),
                'path' => $request->path(),
                'ip' => $request->ip(),
            ];
        }

        // O contexto de quem chamou vence: se alguém logou um `user_id`
        // explícito, é porque quis falar daquele usuário, não do autenticado.
        return $record->with(context: [...$contexto, ...$record->context]);
    }
}

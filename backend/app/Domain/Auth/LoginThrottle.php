<?php

namespace App\Domain\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Limite de tentativas de login, em duas contagens.
 *
 * São dois ataques diferentes, e uma contagem só deixaria um deles passar:
 *
 *   por e-mail + IP  força bruta contra uma conta
 *   por IP           varredura de e-mails, uma tentativa em cada
 *
 * O limite por credencial inclui o IP de propósito. Contar só por e-mail
 * deixaria qualquer pessoa trancar a conta de outra de fora, errando a senha
 * cinco vezes — negação de serviço disfarçada de segurança.
 *
 * Mora aqui, e não no middleware `throttle`, porque o controller precisa das
 * três operações: perguntar, contar e ZERAR no login correto. Zerar exige a
 * mesma chave, e a que o middleware usa internamente é derivada do nome do
 * limitador — detalhe de implementação do framework para depender.
 */
final class LoginThrottle
{
    /** Tentativas erradas na mesma conta, a partir do mesmo IP. */
    public const POR_CREDENCIAL = 5;

    /**
     * Tentativas do mesmo IP, somando todas as contas.
     *
     * Folgado em relação ao outro de propósito: escritório com IP único faz
     * login legítimo de várias pessoas, e o que se quer pegar aqui é a
     * varredura, que passa das dezenas.
     */
    public const POR_IP = 20;

    private const JANELA_SEGUNDOS = 60;

    /** Segundos até poder tentar de novo, ou nulo quando não está bloqueado. */
    public function bloqueadoPor(Request $request): ?int
    {
        foreach ($this->limites($request) as $chave => $maximo) {
            if (RateLimiter::tooManyAttempts($chave, $maximo)) {
                return RateLimiter::availableIn($chave);
            }
        }

        return null;
    }

    public function registrar(Request $request): void
    {
        foreach (array_keys($this->limites($request)) as $chave) {
            RateLimiter::hit($chave, self::JANELA_SEGUNDOS);
        }
    }

    /**
     * Zera a contagem da credencial — e só dela.
     *
     * Um login correto prova que aquela conta não está sob força bruta. Não
     * prova nada sobre o IP: quem varre e-mails pode ter acertado o próprio.
     */
    public function limpar(Request $request): void
    {
        RateLimiter::clear($this->chaveDaCredencial($request));
    }

    /** @return array<string, int> */
    private function limites(Request $request): array
    {
        return [
            $this->chaveDaCredencial($request) => self::POR_CREDENCIAL,
            'login-ip:'.sha1((string) $request->ip()) => self::POR_IP,
        ];
    }

    private function chaveDaCredencial(Request $request): string
    {
        $email = mb_strtolower(trim((string) $request->input('email')));

        return 'login:'.sha1($email.'|'.$request->ip());
    }
}

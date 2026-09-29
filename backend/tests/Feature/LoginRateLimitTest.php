<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rate limit no login.
 *
 * Ficou pendente da etapa 1 com um motivo registrado: escolher um limite que
 * não deixe a própria suíte intermitente exige cuidado. O cuidado está aqui —
 * o limite é por CREDENCIAL, então um teste que erra a senha de um usuário não
 * atrapalha os outros, e cada teste começa com o contador limpo porque o cache
 * da suíte é o de memória.
 *
 * Duas contagens, porque são dois ataques diferentes:
 *
 *   por e-mail + IP  -> força bruta contra uma conta
 *   por IP           -> varredura de e-mails, uma tentativa em cada
 */
class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA_ERRADA = 'senha-errada';

    private function usuario(string $email = 'admin@billing.test'): User
    {
        return User::factory()->create(['email' => $email]);
    }

    private function tentar(string $email, string $senha = self::SENHA_ERRADA)
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => $senha]);
    }

    // --- força bruta contra uma conta ---------------------------------

    public function test_the_sixth_wrong_attempt_on_the_same_account_is_refused(): void
    {
        $this->usuario();

        for ($i = 1; $i <= 5; $i++) {
            $this->tentar('admin@billing.test')->assertUnauthorized();
        }

        $this->tentar('admin@billing.test')->assertStatus(429);
    }

    /** O 429 diz quando tentar de novo, em vez de só recusar. */
    public function test_the_refusal_says_how_long_is_left(): void
    {
        $this->usuario();

        for ($i = 1; $i <= 5; $i++) {
            $this->tentar('admin@billing.test');
        }

        $resposta = $this->tentar('admin@billing.test')->assertStatus(429);

        $this->assertNotEmpty($resposta->headers->get('Retry-After'));
        $this->assertStringContainsString(
            'tentativas',
            mb_strtolower((string) $resposta->json('message')),
        );
    }

    /**
     * O limite é por credencial: quem erra a senha de uma conta não tranca as
     * outras. Sem isso, bastaria errar de propósito para deixar um colega de
     * fora — e a suíte, que faz login com e-mails diferentes, ficaria
     * intermitente.
     */
    public function test_failing_on_one_account_does_not_lock_another(): void
    {
        $this->usuario('um@billing.test');
        $this->usuario('outro@billing.test');

        for ($i = 1; $i <= 5; $i++) {
            $this->tentar('um@billing.test');
        }

        $this->tentar('um@billing.test')->assertStatus(429);
        $this->tentar('outro@billing.test')->assertUnauthorized();
    }

    /** Acertar a senha limpa o contador da credencial. */
    public function test_a_successful_login_clears_the_count(): void
    {
        $this->usuario();

        for ($i = 1; $i <= 4; $i++) {
            $this->tentar('admin@billing.test')->assertUnauthorized();
        }

        $this->tentar('admin@billing.test', 'password')->assertOk();

        for ($i = 1; $i <= 4; $i++) {
            $this->tentar('admin@billing.test')->assertUnauthorized();
        }
    }

    // --- varredura de e-mails -----------------------------------------

    /**
     * Uma tentativa em cada e-mail nunca estoura o limite por credencial. O
     * limite por IP é o que pega esse caso.
     */
    public function test_sweeping_emails_from_the_same_ip_is_refused(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->tentar("inexistente-{$i}@billing.test")->assertUnauthorized();
        }

        $this->tentar('inexistente-21@billing.test')->assertStatus(429);
    }

    // --- o que não muda -----------------------------------------------

    /** Payload inválido não é tentativa de autenticação: não conta. */
    public function test_a_request_without_credentials_does_not_consume_the_limit(): void
    {
        $this->usuario();

        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/api/auth/login', [])->assertStatus(422);
        }

        $this->tentar('admin@billing.test')->assertUnauthorized();
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rate limit no login.
 *
 * This was left pending earlier with a recorded reason: choosing a limit that does not make the
 * suite itself flaky takes care. The care is here — the limit is per CREDENTIAL, so a test that
 * gets one user's password wrong does not disturb the others, and each test starts with a clean
 * counter because the suite's cache is the in-memory one.
 *
 * Two counts, because these are two different attacks:
 *
 *   por e-mail + IP  -> força bruta contra uma conta
 *   per IP           -> sweeping emails, one attempt at each
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

    /** The 429 says when to try again, rather than only refusing. */
    public function test_the_refusal_says_how_long_is_left(): void
    {
        $this->usuario();

        for ($i = 1; $i <= 5; $i++) {
            $this->tentar('admin@billing.test');
        }

        $response = $this->tentar('admin@billing.test')->assertStatus(429);

        $this->assertNotEmpty($response->headers->get('Retry-After'));
        $this->assertStringContainsString(
            'tentativas',
            mb_strtolower((string) $response->json('message')),
        );
    }

    /**
     * The limit is per credential: getting one account's password wrong does not lock the others.
     * Without that, deliberately getting it wrong would be enough to shut a colleague out — and
     * the suite, which logs in with different emails, would become
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

    /** Getting the password right clears the credential's counter. */
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

    // --- sweeping emails ----------------------------------------------

    /**
     * One attempt at each email never trips the per-credential limit. The per-IP limit is what
     * catches that case.
     */
    public function test_sweeping_emails_from_the_same_ip_is_refused(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->tentar("inexistente-{$i}@billing.test")->assertUnauthorized();
        }

        $this->tentar('inexistente-21@billing.test')->assertStatus(429);
    }

    // --- what does not change -----------------------------------------

    /** An invalid payload is not an authentication attempt: it does not count. */
    public function test_a_request_without_credentials_does_not_consume_the_limit(): void
    {
        $this->usuario();

        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/api/auth/login', [])->assertStatus(422);
        }

        $this->tentar('admin@billing.test')->assertUnauthorized();
    }
}

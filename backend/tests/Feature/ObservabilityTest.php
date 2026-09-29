<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Log estruturado e endpoint de health.
 *
 * As duas coisas existem para a mesma pergunta: "o que aconteceu nesta
 * requisição?", feita depois, por quem não estava olhando. O log em JSON com um
 * identificador por requisição é o que permite responder; o health é o que o
 * monitoramento pergunta antes de alguém reclamar.
 */
class ObservabilityTest extends TestCase
{
    use RefreshDatabase;

    // --- identificador de requisição ----------------------------------

    public function test_the_response_carries_a_request_identifier(): void
    {
        $identificador = $this->getJson('/api/health')
            ->assertOk()
            ->headers->get('X-Request-Id');

        $this->assertNotEmpty($identificador);
    }

    /**
     * Identificador vindo de fora é preservado.
     *
     * Quem correlaciona é quem está na borda: o nginx já põe o seu no log de
     * acesso, e gerar outro aqui quebraria a ligação entre as duas pontas.
     */
    public function test_an_identifier_from_outside_is_preserved(): void
    {
        $this->withHeader('X-Request-Id', 'id-da-borda-123')
            ->getJson('/api/health')
            ->assertOk()
            ->assertHeader('X-Request-Id', 'id-da-borda-123');
    }

    // --- o log em JSON ------------------------------------------------

    /**
     * Uma linha de log é um objeto JSON, e traz o identificador e o usuário.
     *
     * O teste redireciona o canal para um arquivo temporário e lê o que saiu.
     * Afirmar sobre o formato exige olhar o formato — um mock do logger
     * provaria que alguém chamou `Log::warning`, não que a linha é parseável.
     */
    public function test_the_log_line_is_json_with_the_identifier_and_the_user(): void
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'log-json-');

        config([
            'logging.default' => 'json',
            'logging.channels.json.with.stream' => $arquivo,
        ]);

        $usuario = User::factory()->create();
        Sanctum::actingAs($usuario);

        $this->withHeader('X-Request-Id', 'id-de-teste')
            ->postJson('/api/billings', [])
            ->assertStatus(422);

        Log::info('mensagem de prova');

        $linhas = array_filter(explode("\n", (string) file_get_contents($arquivo)));
        $ultima = json_decode((string) end($linhas), true);

        @unlink($arquivo);

        $this->assertIsArray($ultima, 'A linha de log não é JSON.');
        $this->assertSame('mensagem de prova', $ultima['message']);
        $this->assertSame('INFO', $ultima['level_name']);
        $this->assertSame('id-de-teste', $ultima['context']['request_id']);
        $this->assertSame($usuario->id, $ultima['context']['user_id']);
        $this->assertSame('POST', $ultima['context']['method']);
        $this->assertSame('api/billings', $ultima['context']['path']);
    }

    /** Tentativa de login falha entra no log: é o rastro de força bruta. */
    public function test_a_failed_login_attempt_is_logged(): void
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'log-json-');

        config([
            'logging.default' => 'json',
            'logging.channels.json.with.stream' => $arquivo,
        ]);

        User::factory()->create(['email' => 'admin@billing.test']);

        $this->postJson('/api/auth/login', [
            'email' => 'admin@billing.test',
            'password' => 'errada',
        ])->assertUnauthorized();

        $conteudo = (string) file_get_contents($arquivo);
        @unlink($arquivo);

        $this->assertStringContainsString('login.falhou', $conteudo);
        $this->assertStringContainsString('admin@billing.test', $conteudo);
    }

    // --- health -------------------------------------------------------

    public function test_health_responds_with_the_checks(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonPath('checks.cache.ok', true);
    }

    /** Sem token: o monitoramento não faz login. */
    public function test_health_is_public(): void
    {
        $this->getJson('/api/health')->assertOk();
    }

    /**
     * Banco fora do ar responde 503, e não 200 com uma mentira.
     *
     * Um health que responde 200 sempre é pior que nenhum: o monitoramento
     * confia nele e para de avisar.
     */
    public function test_health_responds_503_when_the_database_does_not_answer(): void
    {
        DB::shouldReceive('connection')->andThrow(new RuntimeException('sem banco'));

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.database.ok', false);
    }
}

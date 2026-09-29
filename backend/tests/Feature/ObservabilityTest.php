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
 * Structured logging and the health endpoint.
 *
 * Both exist for the same question: "what happened in this request?", asked afterwards, by
 * someone who was not watching. The JSON log with one identifier per request is what makes an
 * answer possible; health is what the monitoring asks before anyone complains.
 */
class ObservabilityTest extends TestCase
{
    use RefreshDatabase;

    // --- the request identifier ---------------------------------------

    public function test_the_response_carries_a_request_identifier(): void
    {
        $identifier = $this->getJson('/api/health')
            ->assertOk()
            ->headers->get('X-Request-Id');

        $this->assertNotEmpty($identifier);
    }

    /**
     * An identifier coming from outside is preserved.
     *
     * Whoever correlates is whoever sits at the edge: nginx already puts its own in the access
     * log, and generating another here would break the link between the two ends.
     */
    public function test_an_identifier_from_outside_is_preserved(): void
    {
        $this->withHeader('X-Request-Id', 'id-da-borda-123')
            ->getJson('/api/health')
            ->assertOk()
            ->assertHeader('X-Request-Id', 'id-da-borda-123');
    }

    // --- the JSON log -------------------------------------------------

    /**
     * A log line is a JSON object, and it carries the identifier and the user.
     *
     * The test redirects the channel to a temporary file and reads what came out. Asserting about
     * the format requires looking at the format — a mocked logger would prove someone called
     * `Log::warning`, not that the line is parseable.
     */
    public function test_the_log_line_is_json_with_the_identifier_and_the_user(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'log-json-');

        config([
            'logging.default' => 'json',
            'logging.channels.json.with.stream' => $file,
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->withHeader('X-Request-Id', 'id-de-teste')
            ->postJson('/api/billings', [])
            ->assertStatus(422);

        Log::info('mensagem de prova');

        $rows = array_filter(explode("\n", (string) file_get_contents($file)));
        $ultima = json_decode((string) end($rows), true);

        @unlink($file);

        $this->assertIsArray($ultima, 'A linha de log não é JSON.');
        $this->assertSame('mensagem de prova', $ultima['message']);
        $this->assertSame('INFO', $ultima['level_name']);
        $this->assertSame('id-de-teste', $ultima['context']['request_id']);
        $this->assertSame($user->id, $ultima['context']['user_id']);
        $this->assertSame('POST', $ultima['context']['method']);
        $this->assertSame('api/billings', $ultima['context']['path']);
    }

    /** A failed login attempt goes into the log: it is the trail of brute force. */
    public function test_a_failed_login_attempt_is_logged(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'log-json-');

        config([
            'logging.default' => 'json',
            'logging.channels.json.with.stream' => $file,
        ]);

        User::factory()->create(['email' => 'admin@billing.test']);

        $this->postJson('/api/auth/login', [
            'email' => 'admin@billing.test',
            'password' => 'errada',
        ])->assertUnauthorized();

        $content = (string) file_get_contents($file);
        @unlink($file);

        $this->assertStringContainsString('login.failed', $content);
        $this->assertStringContainsString('admin@billing.test', $content);
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

    /** No token: the monitoring does not log in. */
    public function test_health_is_public(): void
    {
        $this->getJson('/api/health')->assertOk();
    }

    /**
     * A database that is down answers 503, and not 200 with a lie.
     *
     * A health check that always answers 200 is worse than none: the monitoring trusts it and
     * stops warning.
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

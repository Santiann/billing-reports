<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $password = 'senha-correta'): User
    {
        return User::factory()->create([
            'email' => 'ana@exemplo.test',
            'password' => $password,
        ]);
    }

    public function test_login_with_valid_credentials_returns_a_token(): void
    {
        $this->user();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'ana@exemplo.test',
            'password' => 'senha-correta',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_does_not_expose_the_password_hash(): void
    {
        $this->user();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'ana@exemplo.test',
            'password' => 'senha-correta',
        ]);

        $response->assertOk()->assertJsonMissingPath('user.password');
    }

    public function test_login_with_a_wrong_password_responds_401(): void
    {
        $this->user();

        $this->postJson('/api/auth/login', [
            'email' => 'ana@exemplo.test',
            'password' => 'senha-errada',
        ])->assertUnauthorized();
    }

    public function test_login_with_an_unknown_email_responds_401(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'ninguem@exemplo.test',
            'password' => 'senha-correta',
        ])->assertUnauthorized();
    }

    public function test_login_requires_an_email_and_a_password(): void
    {
        $this->postJson('/api/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    // The two tests below are a pair. The 401 on its own does not prove the route works — it
    // would prove the same if the route were broken.

    public function test_a_protected_route_without_a_token_responds_401(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_a_protected_route_with_a_token_responds_200(): void
    {
        $user = $this->user();
        $token = $user->createToken('teste')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'ana@exemplo.test');
    }

    public function test_logout_revokes_the_token_it_was_called_with(): void
    {
        $user = $this->user();
        $token = $user->createToken('teste')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // In production each request is a fresh process; inside a test the guard keeps the
        // already-resolved user in memory. Without flushing, the assertion below would pass even
        // with logout broken.
        $this->app['auth']->forgetGuards();

        // The same token must no longer open a protected route: without this second assertion the
        // test would only prove the endpoint answers 200.
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_logout_without_a_token_responds_401(): void
    {
        $this->postJson('/api/auth/logout')->assertUnauthorized();
    }
}

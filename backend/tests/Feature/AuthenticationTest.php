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

    public function test_login_com_credenciais_validas_devolve_um_token(): void
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

    public function test_login_nao_expoe_o_hash_da_senha(): void
    {
        $this->user();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'ana@exemplo.test',
            'password' => 'senha-correta',
        ]);

        $response->assertOk()->assertJsonMissingPath('user.password');
    }

    public function test_login_com_senha_incorreta_responde_401(): void
    {
        $this->user();

        $this->postJson('/api/auth/login', [
            'email' => 'ana@exemplo.test',
            'password' => 'senha-errada',
        ])->assertUnauthorized();
    }

    public function test_login_com_email_inexistente_responde_401(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'ninguem@exemplo.test',
            'password' => 'senha-correta',
        ])->assertUnauthorized();
    }

    public function test_login_exige_email_e_senha(): void
    {
        $this->postJson('/api/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    // Os dois testes abaixo são um par. O 401 sozinho não prova que a rota
    // funciona — provaria o mesmo se ela estivesse quebrada.

    public function test_rota_protegida_sem_token_responde_401(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_rota_protegida_com_token_responde_200(): void
    {
        $user = $this->user();
        $token = $user->createToken('teste')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'ana@exemplo.test');
    }

    public function test_logout_revoga_o_token_usado(): void
    {
        $user = $this->user();
        $token = $user->createToken('teste')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Em produção cada request é um processo novo; dentro de um teste o
        // guard mantém o usuário já resolvido em memória. Sem esvaziar, a
        // asserção abaixo passaria mesmo com o logout quebrado.
        $this->app['auth']->forgetGuards();

        // O mesmo token não pode mais abrir uma rota protegida: sem esta
        // segunda asserção o teste provaria só que o endpoint responde 200.
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_logout_sem_token_responde_401(): void
    {
        $this->postJson('/api/auth/logout')->assertUnauthorized();
    }
}

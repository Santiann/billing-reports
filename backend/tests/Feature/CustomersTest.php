<?php

namespace Tests\Feature;

use App\Domain\Customer\CustomerStatus;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomersTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    // --- proteção -----------------------------------------------------

    public function test_listagem_exige_autenticacao(): void
    {
        $this->getJson('/api/customers')->assertUnauthorized();
    }

    public function test_listagem_responde_para_usuario_autenticado(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/customers')->assertOk();
    }

    public function test_cadastro_exige_autenticacao(): void
    {
        $this->postJson('/api/customers', [])->assertUnauthorized();
    }

    // --- listagem -----------------------------------------------------

    public function test_listagem_pagina_no_banco(): void
    {
        $this->actingAsUser();
        Customer::factory()->count(25)->create();

        $response = $this->getJson('/api/customers?per_page=10')->assertOk();

        // A página traz 10 registros, mas o total conhece os 25: prova que o
        // recorte é do banco e não de uma coleção carregada inteira.
        $this->assertCount(10, $response->json('data'));
        $this->assertSame(25, $response->json('meta.total'));
        $this->assertSame(10, $response->json('meta.per_page'));
    }

    public function test_segunda_pagina_traz_registros_diferentes(): void
    {
        $this->actingAsUser();
        Customer::factory()->count(25)->create();

        $primeira = $this->getJson('/api/customers?per_page=10&page=1')->json('data.*.id');
        $segunda = $this->getJson('/api/customers?per_page=10&page=2')->json('data.*.id');

        $this->assertEmpty(array_intersect($primeira, $segunda));
    }

    public function test_per_page_tem_teto(): void
    {
        $this->actingAsUser();

        // Sem teto, ?per_page=999999 seria um jeito trivial de derrubar a API.
        $this->getJson('/api/customers?per_page=100000')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_filtra_por_status(): void
    {
        $this->actingAsUser();
        Customer::factory()->count(3)->create();
        Customer::factory()->inactive()->count(2)->create();

        $response = $this->getJson('/api/customers?status=inactive')->assertOk();

        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_busca_por_nome_documento_e_email(): void
    {
        $this->actingAsUser();
        Customer::factory()->create([
            'name' => 'Padaria Aurora',
            'document' => '99988877766',
            'email' => 'contato@aurora.test',
        ]);
        Customer::factory()->count(4)->create();

        foreach (['Aurora', '99988877766', 'contato@aurora'] as $termo) {
            $response = $this->getJson('/api/customers?search='.urlencode($termo))->assertOk();

            $this->assertSame(1, $response->json('meta.total'), "busca falhou para: {$termo}");
            $this->assertSame('Padaria Aurora', $response->json('data.0.name'));
        }
    }

    public function test_ordena_por_coluna_permitida(): void
    {
        $this->actingAsUser();
        Customer::factory()->create(['name' => 'Zebra']);
        Customer::factory()->create(['name' => 'Abelha']);

        $nomes = $this->getJson('/api/customers?sort=name&direction=asc')
            ->assertOk()
            ->json('data.*.name');

        $this->assertSame('Abelha', $nomes[0]);
    }

    public function test_ordenacao_por_coluna_arbitraria_e_rejeitada(): void
    {
        $this->actingAsUser();

        // Sem allowlist, o parâmetro entraria cru no ORDER BY.
        $this->getJson('/api/customers?sort=password')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sort');
    }

    // --- cadastro -----------------------------------------------------

    public function test_cadastra_cliente(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/customers', [
            'name' => 'Mercado Central',
            'document' => '12345678901',
            'email' => 'contato@central.test',
            'status' => 'active',
        ])->assertCreated()->assertJsonPath('data.name', 'Mercado Central');

        $this->assertDatabaseHas('customers', ['document' => '12345678901']);
    }

    public function test_documento_e_gravado_sem_mascara(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/customers', [
            'name' => 'Mercado Central',
            'document' => '123.456.789-01',
            'email' => 'contato@central.test',
            'status' => 'active',
        ])->assertCreated();

        // Guardar com máscara faria a busca depender do formato digitado.
        $this->assertDatabaseHas('customers', ['document' => '12345678901']);
    }

    public function test_documento_duplicado_e_rejeitado(): void
    {
        $this->actingAsUser();
        Customer::factory()->create(['document' => '12345678901']);

        $this->postJson('/api/customers', [
            'name' => 'Outro',
            'document' => '12345678901',
            'email' => 'outro@test.test',
            'status' => 'active',
        ])->assertUnprocessable()->assertJsonValidationErrors('document');
    }

    public function test_cadastro_valida_campos_obrigatorios(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/customers', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'document', 'email', 'status']);
    }

    // --- visualização e edição ---------------------------------------

    public function test_visualiza_um_cliente(): void
    {
        $this->actingAsUser();
        $customer = Customer::factory()->create(['name' => 'Acme']);

        $this->getJson("/api/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme');
    }

    public function test_cliente_inexistente_responde_404(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/customers/999999')->assertNotFound();
    }

    public function test_edita_cliente(): void
    {
        $this->actingAsUser();
        $customer = Customer::factory()->create(['name' => 'Nome Antigo']);

        $this->putJson("/api/customers/{$customer->id}", [
            'name' => 'Nome Novo',
            'document' => $customer->document,
            'email' => $customer->email,
            'status' => CustomerStatus::Inactive->value,
        ])->assertOk()->assertJsonPath('data.name', 'Nome Novo');

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'Nome Novo',
            'status' => 'inactive',
        ]);
    }

    public function test_edicao_nao_conflita_com_o_proprio_documento(): void
    {
        $this->actingAsUser();
        $customer = Customer::factory()->create(['document' => '12345678901']);

        // A unique precisa ignorar o próprio registro, senão ninguém consegue
        // salvar uma edição sem trocar de documento.
        $this->putJson("/api/customers/{$customer->id}", [
            'name' => 'Nome Novo',
            'document' => '12345678901',
            'email' => $customer->email,
            'status' => 'active',
        ])->assertOk();
    }
}

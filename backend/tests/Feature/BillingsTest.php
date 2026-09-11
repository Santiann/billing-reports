<?php

namespace Tests\Feature;

use App\Domain\Billing\BillingStatus;
use App\Models\Billing;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => Customer::factory()->create()->id,
            'description' => 'Mensalidade de junho',
            'original_amount' => '1500.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-06-01',
            'due_date' => '2026-07-01',
        ], $overrides);
    }

    // --- proteção -----------------------------------------------------

    public function test_listagem_exige_autenticacao(): void
    {
        $this->getJson('/api/billings')->assertUnauthorized();
    }

    public function test_listagem_responde_para_usuario_autenticado(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/billings')->assertOk();
    }

    // --- listagem -----------------------------------------------------

    public function test_listagem_pagina_no_banco(): void
    {
        $this->actingAsUser();
        Billing::factory()->count(25)->create();

        $response = $this->getJson('/api/billings?per_page=10')->assertOk();

        $this->assertCount(10, $response->json('data'));
        $this->assertSame(25, $response->json('meta.total'));
    }

    public function test_listagem_nao_faz_consulta_n_mais_um(): void
    {
        $this->actingAsUser();

        // Dez cobranças de dez clientes diferentes: sem eager loading, montar
        // a resposta dispararia um SELECT de cliente por linha.
        Billing::factory()->count(10)->create();

        DB::enableQueryLog();
        $this->getJson('/api/billings?per_page=10')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            3,
            count($queries),
            'Esperado count + select de cobranças + select de clientes. Recebido: '
                .count($queries).' consultas.',
        );
    }

    public function test_filtra_por_cliente(): void
    {
        $this->actingAsUser();
        $customer = Customer::factory()->create();
        Billing::factory()->count(3)->for($customer)->create();
        Billing::factory()->count(5)->create();

        $response = $this->getJson("/api/billings?customer_id={$customer->id}")->assertOk();

        $this->assertSame(3, $response->json('meta.total'));
    }

    public function test_filtra_por_status(): void
    {
        $this->actingAsUser();
        Billing::factory()->count(3)->create();
        Billing::factory()->count(2)->paid()->create();

        $response = $this->getJson('/api/billings?status=paid')->assertOk();

        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_busca_por_descricao(): void
    {
        $this->actingAsUser();
        Billing::factory()->create(['description' => 'Consultoria tributária']);
        Billing::factory()->count(4)->create(['description' => 'Outra coisa']);

        $response = $this->getJson('/api/billings?search=tribut')->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_ordenacao_por_coluna_arbitraria_e_rejeitada(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/billings?sort=customer_id;drop')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sort');
    }

    // --- cadastro -----------------------------------------------------

    public function test_cadastra_cobranca(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/billings', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.description', 'Mensalidade de junho');
    }

    public function test_cobranca_nasce_pendente_e_sem_pagamento(): void
    {
        $this->actingAsUser();

        // Status e dados de pagamento não são aceitos do cliente: aceitar
        // 'paid' aqui criaria uma cobrança paga sem os valores congelados.
        $response = $this->postJson('/api/billings', $this->payload([
            'status' => 'paid',
            'payment_date' => '2026-06-15',
            'paid_amount' => '9999.00',
        ]))->assertCreated();

        $this->assertSame(BillingStatus::Pending->value, $response->json('data.status'));

        $billing = Billing::findOrFail($response->json('data.id'));
        $this->assertNull($billing->payment_date);
        $this->assertNull($billing->paid_amount);
        $this->assertNull($billing->paid_interest_amount);
    }

    public function test_vencimento_nao_pode_ser_anterior_a_emissao(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/billings', $this->payload([
            'issue_date' => '2026-07-01',
            'due_date' => '2026-06-01',
        ]))->assertUnprocessable()->assertJsonValidationErrors('due_date');
    }

    public function test_cliente_inexistente_e_rejeitado(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/billings', $this->payload(['customer_id' => 999999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_id');
    }

    public function test_cadastro_valida_campos_obrigatorios(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/billings', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'customer_id', 'description', 'original_amount',
                'monthly_interest_rate', 'issue_date', 'due_date',
            ]);
    }

    // --- visualização e edição ---------------------------------------

    public function test_visualiza_uma_cobranca_com_o_cliente(): void
    {
        $this->actingAsUser();
        $customer = Customer::factory()->create(['name' => 'Acme Ltda']);
        $billing = Billing::factory()->for($customer)->create();

        $this->getJson("/api/billings/{$billing->id}")
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Acme Ltda');
    }

    public function test_cobranca_inexistente_responde_404(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/billings/999999')->assertNotFound();
    }

    public function test_edita_cobranca_pendente(): void
    {
        $this->actingAsUser();
        $billing = Billing::factory()->create();

        $this->putJson("/api/billings/{$billing->id}", $this->payload([
            'customer_id' => $billing->customer_id,
            'description' => 'Descrição corrigida',
        ]))->assertOk()->assertJsonPath('data.description', 'Descrição corrigida');
    }

    public function test_cobranca_paga_nao_pode_ser_editada(): void
    {
        $this->actingAsUser();
        $billing = Billing::factory()->paid()->create();

        // Editar valor ou taxa de uma cobrança paga invalidaria os valores
        // congelados no pagamento, que não são recalculáveis.
        $this->putJson("/api/billings/{$billing->id}", $this->payload([
            'customer_id' => $billing->customer_id,
            'original_amount' => '1.00',
        ]))->assertUnprocessable();

        $this->assertNotSame('1.00', $billing->fresh()->original_amount);
    }
}

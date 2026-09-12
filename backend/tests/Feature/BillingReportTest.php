<?php

namespace Tests\Feature;

use App\Models\Billing;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingReportTest extends TestCase
{
    use RefreshDatabase;

    private const HOJE = '2026-06-15 09:30:00';

    private function actingAsUser(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    // --- proteção -----------------------------------------------------

    public function test_relatorio_exige_autenticacao(): void
    {
        $this->getJson('/api/reports/billings')->assertUnauthorized();
    }

    public function test_relatorio_responde_para_usuario_autenticado(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/reports/billings')->assertOk();
    }

    // --- período e base de data ---------------------------------------

    public function test_periodo_por_data_de_emissao(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        Billing::factory()->create(['issue_date' => '2026-03-10', 'due_date' => '2026-04-10']);
        Billing::factory()->create(['issue_date' => '2026-05-20', 'due_date' => '2026-06-20']);

        $response = $this->getJson(
            '/api/reports/billings?date_field=issue_date&start_date=2026-03-01&end_date=2026-03-31',
        )->assertOk();

        $this->assertSame(1, $response->json('totals.count'));
    }

    public function test_periodo_por_data_de_vencimento(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        Billing::factory()->create(['issue_date' => '2026-03-10', 'due_date' => '2026-04-10']);
        Billing::factory()->create(['issue_date' => '2026-05-20', 'due_date' => '2026-06-20']);

        // Mesmo par de cobranças, outra base de data, outro recorte: é isso
        // que a escolha da base precisa provar.
        $response = $this->getJson(
            '/api/reports/billings?date_field=due_date&start_date=2026-06-01&end_date=2026-06-30',
        )->assertOk();

        $this->assertSame(1, $response->json('totals.count'));
    }

    public function test_periodo_por_data_de_pagamento_ignora_nao_pagas(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        Billing::factory()->paid()->create();
        Billing::factory()->count(3)->create();

        $response = $this->getJson(
            '/api/reports/billings?date_field=payment_date&start_date=2026-01-01&end_date=2026-12-31',
        )->assertOk();

        // Cobrança sem pagamento tem payment_date nulo e fica fora do recorte.
        $this->assertSame(1, $response->json('totals.count'));
    }

    public function test_base_de_data_invalida_e_rejeitada(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/reports/billings?date_field=created_at')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_field');
    }

    public function test_data_final_anterior_a_inicial_e_rejeitada(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/reports/billings?start_date=2026-06-30&end_date=2026-06-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_date');
    }

    // --- demais filtros -----------------------------------------------

    public function test_filtra_por_cliente(): void
    {
        $this->actingAsUser();
        $customer = Customer::factory()->create();
        Billing::factory()->count(2)->for($customer)->create();
        Billing::factory()->count(5)->create();

        $response = $this->getJson("/api/reports/billings?customer_id={$customer->id}")->assertOk();

        $this->assertSame(2, $response->json('totals.count'));
    }

    public function test_filtra_por_status_paga(): void
    {
        $this->actingAsUser();
        Billing::factory()->count(3)->paid()->create();
        Billing::factory()->count(4)->create();

        $response = $this->getJson('/api/reports/billings?status=paid')->assertOk();

        $this->assertSame(3, $response->json('totals.count'));
    }

    public function test_filtra_por_status_pendente(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        Billing::factory()->count(4)->create();          // pendentes no prazo
        Billing::factory()->count(2)->overdue(30)->create(); // pendentes vencidas
        Billing::factory()->count(3)->paid()->create();

        // `pending` é o status gravado e inclui as vencidas, que são pendentes
        // com vencimento no passado. Quem quer só as vencidas usa `overdue`.
        $response = $this->getJson('/api/reports/billings?status=pending')->assertOk();

        $this->assertSame(6, $response->json('totals.count'));
    }

    public function test_filtra_por_vencida_que_e_condicao_derivada(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        Billing::factory()->count(2)->overdue(30)->create();
        Billing::factory()->count(3)->create();       // pendentes, no prazo
        Billing::factory()->count(4)->paidLate()->create();  // pagas, não contam

        $response = $this->getJson('/api/reports/billings?status=overdue')->assertOk();

        // "Vencida" não existe como valor gravado: é pendente com vencimento
        // no passado, resolvido em SQL.
        $this->assertSame(2, $response->json('totals.count'));
    }

    // --- ordenação ----------------------------------------------------

    public function test_ordena_por_valor_atualizado(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        // Valor original menor, mas muito mais atrasada: o valor atualizado
        // inverte a ordem. Só é possível ordenar assim porque o cálculo
        // existe em SQL.
        Billing::factory()->overdue(5)->create([
            'original_amount' => '1000.00', 'monthly_interest_rate' => '0.0200',
        ]);
        Billing::factory()->overdue(720)->create([
            'original_amount' => '900.00', 'monthly_interest_rate' => '0.1000',
        ]);

        $valores = $this->getJson('/api/reports/billings?sort=updated_amount&direction=desc')
            ->assertOk()
            ->json('data.*.updated_amount');

        $this->assertGreaterThan((float) $valores[1], (float) $valores[0]);
        $this->assertGreaterThan(900.0, (float) $valores[0]);
    }

    public function test_ordenacao_por_coluna_arbitraria_e_rejeitada(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/reports/billings?sort=(select 1)')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sort');
    }

    // --- totalizadores ------------------------------------------------

    public function test_totalizadores_cobrem_o_conjunto_inteiro_e_nao_a_pagina(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        // 25 cobranças de R$ 100,00 sem juros, numa página de 10.
        Billing::factory()->count(25)->create([
            'original_amount' => '100.00',
            'monthly_interest_rate' => '0.0000',
        ]);

        $response = $this->getJson('/api/reports/billings?per_page=10')->assertOk();

        $this->assertCount(10, $response->json('data'), 'A página deveria trazer 10.');

        // O erro fácil é somar a página: daria 1000,00.
        $this->assertSame(25, $response->json('totals.count'));
        $this->assertSame('2500.00', $response->json('totals.original_amount'));
        $this->assertSame('2500.00', $response->json('totals.updated_amount'));
    }

    public function test_totalizadores_respeitam_o_filtro(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $customer = Customer::factory()->create();
        Billing::factory()->count(3)->for($customer)->create([
            'original_amount' => '100.00', 'monthly_interest_rate' => '0.0000',
        ]);
        Billing::factory()->count(7)->create([
            'original_amount' => '999.00', 'monthly_interest_rate' => '0.0000',
        ]);

        $response = $this->getJson("/api/reports/billings?customer_id={$customer->id}")->assertOk();

        $this->assertSame(3, $response->json('totals.count'));
        $this->assertSame('300.00', $response->json('totals.original_amount'));
    }

    public function test_total_de_juros_soma_o_conjunto_filtrado(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        // 1000 * 1.02^1 = 1020,00 -> 20,00 de juros, tres vezes.
        Billing::factory()->count(3)->overdue(30)->create([
            'original_amount' => '1000.00', 'monthly_interest_rate' => '0.0200',
        ]);

        $response = $this->getJson('/api/reports/billings')->assertOk();

        $this->assertSame('60.00', $response->json('totals.interest_amount'));
        $this->assertSame('3060.00', $response->json('totals.updated_amount'));
    }

    public function test_recebido_e_pendente_sao_separados(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        Billing::factory()->count(2)->paid()->create([
            'original_amount' => '500.00', 'monthly_interest_rate' => '0.0000',
        ]);
        Billing::factory()->count(3)->create([
            'original_amount' => '200.00', 'monthly_interest_rate' => '0.0000',
        ]);

        $response = $this->getJson('/api/reports/billings')->assertOk();

        // Recebido vem das colunas congeladas; pendente, do valor atualizado.
        $this->assertSame('1000.00', $response->json('totals.paid_amount'));
        $this->assertSame('600.00', $response->json('totals.pending_amount'));
    }

    // --- eco dos filtros ----------------------------------------------

    public function test_resposta_ecoa_os_filtros_aplicados(): void
    {
        $this->actingAsUser();

        // As exportações precisam imprimir período e filtros no arquivo, e
        // devem sair da mesma fonte que a tela usa.
        $response = $this->getJson(
            '/api/reports/billings?date_field=issue_date&start_date=2026-01-01&end_date=2026-12-31&status=paid',
        )->assertOk();

        $this->assertSame('issue_date', $response->json('filters.date_field'));
        $this->assertSame('2026-01-01', $response->json('filters.start_date'));
        $this->assertSame('2026-12-31', $response->json('filters.end_date'));
        $this->assertSame('paid', $response->json('filters.status'));
    }

    public function test_resposta_informa_se_o_pdf_cabe_no_teto(): void
    {
        $this->actingAsUser();
        config(['reports.pdf_max_rows' => 3]);

        \App\Models\Billing::factory()->count(2)->create();
        $this->getJson('/api/reports/billings')
            ->assertOk()
            ->assertJsonPath('export.pdf_available', true)
            ->assertJsonPath('export.pdf_max_rows', 3);

        \App\Models\Billing::factory()->count(2)->create();

        // A tela usa isto para desabilitar o botão antes do clique, em vez de
        // mandar o usuário bater num 422.
        $this->getJson('/api/reports/billings')
            ->assertOk()
            ->assertJsonPath('export.pdf_available', false);
    }

    public function test_listagem_nao_faz_consulta_n_mais_um(): void
    {
        $this->actingAsUser();
        Billing::factory()->count(10)->create();

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->getJson('/api/reports/billings?per_page=10')->assertOk();
        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // count da paginação + linhas + clientes + agregação dos totais.
        $this->assertLessThanOrEqual(4, count($queries));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Billing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * O enunciado exige que o valor calculado seja "consistente em todas as telas
 * e relatórios".
 *
 * `InterestCalculatorTest` prova que as duas FACES do calculador concordam.
 * Este prova o degrau seguinte: que os três ENDPOINTS que expõem o valor
 * concordam entre si, pela HTTP, com o mesmo registro.
 *
 * São caminhos diferentes de propósito — a cobrança isolada calcula em PHP, e
 * a listagem e o relatório calculam em SQL dentro do SELECT. É exatamente por
 * serem caminhos diferentes que precisam ser confrontados.
 */
class InterestConsistencyAcrossEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private const HOJE = '2026-06-15 09:30:00';

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function cenarios(): array
    {
        //        [ valor,       taxa,     dias de atraso ]
        return [
            'em dia' => ['1000.00', '0.0200', -10],
            'vencida há 1 dia' => ['1000.00', '0.0200', 1],
            'vencida há 45 dias' => ['1234.57', '0.0333', 45],
            'vencida há 400 dias' => ['987654.31', '0.0250', 400],
            'taxa zero' => ['500.00', '0.0000', 90],
            'centavo mínimo' => ['0.01', '0.1500', 365],
        ];
    }

    #[DataProvider('cenarios')]
    public function test_os_tres_endpoints_devolvem_o_mesmo_valor(
        string $amount,
        string $rate,
        int $daysLate,
    ): void {
        $this->travelTo(self::HOJE);
        Sanctum::actingAs(User::factory()->create());

        $billing = $daysLate > 0
            ? Billing::factory()->overdue($daysLate)->create([
                'original_amount' => $amount,
                'monthly_interest_rate' => $rate,
            ])
            : Billing::factory()->create([
                'original_amount' => $amount,
                'monthly_interest_rate' => $rate,
            ]);

        // Cobrança isolada: face PHP.
        $isolada = $this->getJson("/api/billings/{$billing->id}")->assertOk()->json('data');

        // Listagem de cobranças: face SQL, via selectRaw.
        $listagem = $this->getJson('/api/billings')->assertOk()->json('data.0');

        // Relatório: face SQL, por outro caminho de consulta.
        $relatorio = $this->getJson('/api/reports/billings')->assertOk()->json('data.0');

        foreach (['updated_amount', 'interest_amount'] as $campo) {
            $this->assertSame(
                $isolada[$campo],
                $listagem[$campo],
                "Cobrança isolada e listagem divergiram em {$campo}.",
            );

            $this->assertSame(
                $isolada[$campo],
                $relatorio[$campo],
                "Cobrança isolada e relatório divergiram em {$campo}.",
            );
        }
    }

    public function test_totalizador_do_relatorio_bate_com_a_soma_das_linhas(): void
    {
        $this->travelTo(self::HOJE);
        Sanctum::actingAs(User::factory()->create());

        Billing::factory()->count(4)->overdue(30)->create([
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
        ]);

        $resposta = $this->getJson('/api/reports/billings?per_page=100')->assertOk();

        // O totalizador vem de uma consulta de agregação separada. Somar as
        // linhas exibidas e comparar é o único jeito de provar que as duas
        // consultas falam do mesmo conjunto e da mesma regra.
        $somaDasLinhas = array_sum(array_map(
            fn (array $linha) => (float) $linha['updated_amount'],
            $resposta->json('data'),
        ));

        $this->assertSame(
            number_format($somaDasLinhas, 2, '.', ''),
            $resposta->json('totals.updated_amount'),
        );
    }

    public function test_pagamento_na_data_exata_do_vencimento_nao_gera_juros(): void
    {
        $this->travelTo(self::HOJE);
        Sanctum::actingAs(User::factory()->create());

        $billing = Billing::factory()->create([
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-05-01',
            'due_date' => '2026-06-01',
        ]);

        // Fronteira: pagar no próprio dia do vencimento é zero dia de atraso.
        $this->postJson("/api/billings/{$billing->id}/payment", [
            'payment_date' => '2026-06-01',
        ])->assertOk();

        $billing->refresh();

        $this->assertSame('0.00', $billing->paid_interest_amount);
        $this->assertSame('1000.00', $billing->paid_amount);
    }
}

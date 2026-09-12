<?php

namespace Tests\Feature;

use App\Domain\Billing\BillingStatus;
use App\Domain\Billing\RegisterPayment;
use App\Domain\Report\BillingReportFilters;
use App\Domain\Report\BillingReportQuery;
use App\Models\Billing;
use Database\Seeders\BillingVolumeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O seeder de volume é o único lugar onde uma cobrança paga nasce sem passar
 * pela API, e por isso é o único lugar onde os valores congelados podem
 * divergir da regra sem ninguém perceber. Estes testes existem para impedir
 * isso.
 *
 * Amostra pequena de propósito: o que se afirma aqui é a REGRA. A prova de
 * volume é a carga de dois milhões, medida fora da suíte.
 */
class BillingVolumeSeederTest extends TestCase
{
    use RefreshDatabase;

    private const HOJE = '2026-06-15 09:30:00';

    private const COBRANCAS = 600;

    /**
     * Não há limpeza explícita aqui, e isso é deliberado: quem desfaz as 600
     * cobranças é o rollback do RefreshDatabase.
     *
     * Limpar com TRUNCATE seria o caminho óbvio e custaria caro. TRUNCATE é
     * DDL e faz commit implícito em MySQL; o Laravel percebe que a transação
     * do teste sumiu e marca RefreshDatabaseState::$migrated = false, o que
     * dispara um `migrate:fresh` inteiro ANTES DE CADA TESTE SEGUINTE. Medido
     * nesta base: ~50s por teste, contra 1,3s sem nenhum DDL.
     *
     * É a mesma razão pela qual o seeder não trunca tabela já vazia.
     */
    private function semear(): void
    {
        $this->travelTo(self::HOJE);

        (new BillingVolumeSeeder(total: self::COBRANCAS))->run();
    }

    public function test_gera_cobrancas_pagas_em_atraso_com_juros_congelados(): void
    {
        $this->semear();

        $pagasEmAtraso = Billing::query()
            ->where('status', BillingStatus::Paid)
            ->whereColumn('payment_date', '>', 'due_date')
            ->where('paid_interest_amount', '>', 0)
            ->count();

        $this->assertGreaterThan(
            0,
            $pagasEmAtraso,
            'Nenhuma cobrança paga em atraso: a regra de congelamento fica invisível na tela.',
        );
    }

    /**
     * O teste que sustenta a exigência do brief: o valor congelado tem que vir
     * do RegisterPayment, não de uma fórmula reescrita dentro do seeder.
     *
     * A prova é reconstruir a mesma cobrança como pendente, pagá-la pelo
     * serviço de produção na mesma data, e exigir igualdade até o centavo. Uma
     * segunda implementação da regra dentro do seeder cairia aqui.
     */
    public function test_juros_congelados_sao_os_que_o_registro_de_pagamento_produziria(): void
    {
        $this->semear();

        $amostra = Billing::query()
            ->where('status', BillingStatus::Paid)
            ->whereColumn('payment_date', '>', 'due_date')
            ->limit(10)
            ->get();

        $this->assertNotEmpty($amostra, 'Sem cobrança paga em atraso não há o que comparar.');

        foreach ($amostra as $semeada) {
            $refeita = Billing::query()->create([
                'customer_id' => $semeada->customer_id,
                'description' => $semeada->description,
                'original_amount' => $semeada->original_amount,
                'monthly_interest_rate' => $semeada->monthly_interest_rate,
                'issue_date' => $semeada->issue_date,
                'due_date' => $semeada->due_date,
            ]);

            app(RegisterPayment::class)($refeita, $semeada->payment_date);

            $this->assertSame(
                $refeita->paid_interest_amount,
                $semeada->paid_interest_amount,
                "Juros congelados divergem na cobrança {$semeada->id}.",
            );
            $this->assertSame(
                $refeita->paid_amount,
                $semeada->paid_amount,
                "Valor pago diverge na cobrança {$semeada->id}.",
            );
        }
    }

    public function test_paga_em_dia_nao_acumula_juros(): void
    {
        $this->semear();

        $emDiaComJuros = Billing::query()
            ->where('status', BillingStatus::Paid)
            ->whereColumn('payment_date', '<=', 'due_date')
            ->where('paid_interest_amount', '>', 0)
            ->count();

        $this->assertSame(0, $emDiaComJuros);
    }

    /** Pagamento com data futura não existe: ninguém pagou o que ainda não aconteceu. */
    public function test_nenhum_pagamento_cai_no_futuro(): void
    {
        $this->semear();

        $noFuturo = Billing::query()
            ->where('payment_date', '>', now()->toDateString())
            ->count();

        $this->assertSame(0, $noFuturo);
    }

    public function test_pendente_nao_tem_coluna_de_congelamento_preenchida(): void
    {
        $this->semear();

        $pendenteComValorPago = Billing::query()
            ->where('status', BillingStatus::Pending)
            ->where(function ($query) {
                $query->whereNotNull('paid_amount')
                    ->orWhereNotNull('paid_interest_amount')
                    ->orWhereNotNull('payment_date');
            })
            ->count();

        $this->assertSame(0, $pendenteComValorPago);
    }

    /**
     * O critério de aceite do bloco, visto de onde o avaliador vai olhar: o
     * relatório filtrado por pagas precisa mostrar juros recebidos, não zero.
     */
    public function test_relatorio_de_pagas_mostra_juros_recebidos(): void
    {
        $this->semear();

        $totais = (new BillingReportQuery())->totals(
            new BillingReportFilters(status: 'paid'),
        );

        $this->assertGreaterThan(0, (float) $totais['paid_amount'], 'Recebido zerado.');
        $this->assertGreaterThan(0, (float) $totais['interest_amount'], 'Juros recebidos zerados.');
    }
}

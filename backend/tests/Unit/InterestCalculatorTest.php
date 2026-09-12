<?php

namespace Tests\Unit;

use App\Domain\Billing\InterestCalculator;
use App\Domain\Billing\RegisterPayment;
use App\Models\Billing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * O teste que sustenta a exigência de resultado consistente entre telas e
 * relatório.
 *
 * O InterestCalculator tem duas faces — uma em SQL, usada na listagem e nas
 * agregações, e uma em PHP, usada para exibir uma cobrança isolada. Elas podem
 * divergir silenciosamente: arredondamento, precisão de DECIMAL contra float,
 * contagem de dias. Aqui a mesma matriz de casos passa pelas duas e os
 * resultados são comparados até o centavo.
 *
 * Toca o banco de propósito: a face SQL só existe dentro do MySQL. Fica em
 * tests/Unit porque o objeto sob teste é o calculador, não uma rota.
 */
class InterestCalculatorTest extends TestCase
{
    use RefreshDatabase;

    /** Congelado: "vencida há 30 dias" precisa significar o mesmo amanhã. */
    private const HOJE = '2026-06-15 09:30:00';

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string|null}>
     */
    public static function casos(): array
    {
        //        [ valor,      taxa,     vencimento,   pagamento ]
        return [
            'em dia, vence amanhã' => ['1000.00', '0.0200', '2026-06-16', null],
            'vence hoje' => ['1000.00', '0.0200', '2026-06-15', null],
            'vencida há 1 dia' => ['1000.00', '0.0200', '2026-06-14', null],
            'vencida há 30 dias' => ['1000.00', '0.0200', '2026-05-16', null],
            'vencida há 400 dias' => ['1000.00', '0.0200', '2025-05-11', null],
            'taxa zero, vencida há 90 dias' => ['1000.00', '0.0000', '2026-03-17', null],
            'taxa alta, vencida há 45 dias' => ['1000.00', '0.1500', '2026-05-01', null],
            'centavos quebrados' => ['1234.57', '0.0333', '2026-04-02', null],
            'valor alto e centavo ímpar' => ['987654.31', '0.0250', '2026-01-07', null],
            // Caso escolhido por medição, não por intuição: um varrimento de
            // 900 dias x 6 taxas x 3 valores achou 78 combinações em que a
            // divisão DECIMAL do MySQL (400/30 = 13.3333, truncado em quatro
            // casas) muda o centavo contra a divisão double. Esta é uma
            // delas, e é o caso que guarda o `/ 30e0` do compoundSql:
            // sem ele, DECIMAL da 1363158.13 e PHP da 1363158.14.
            'divergencia decimal vs double' => ['987654.31', '0.0350', '2025-09-07', null],
            // Outro caso achado por varredura, e de natureza diferente do
            // anterior: aqui a conta cai EXATAMENTE no meio centavo. 4224,10
            // a 5% por 30 dias dá 4435,305. PHP arredonda meio para longe do
            // zero e dá 4435,31; o ROUND do MySQL sobre DOUBLE arredonda meio
            // para par e dá 4435,30. Varredura de 200.000 combinações achou
            // uma divergência dessas, e a base de dois milhões achou outra.
            'empate no meio centavo' => ['4224.10', '0.0500', '2026-05-16', null],
            'empate no meio centavo, valor alto' => ['435254.90', '0.0500', '2026-05-16', null],
            'paga em dia' => ['1500.00', '0.0200', '2026-05-20', '2026-05-18'],
            'paga em atraso' => ['1500.00', '0.0200', '2026-04-10', '2026-05-20'],
        ];
    }

    #[DataProvider('casos')]
    public function test_as_duas_faces_devolvem_o_mesmo_valor(
        string $amount,
        string $rate,
        string $dueDate,
        ?string $paymentDate,
    ): void {
        $this->travelTo(self::HOJE);

        $billing = $this->makeBilling($amount, $rate, $dueDate, $paymentDate);

        $php = (new InterestCalculator())->for($billing);
        $sql = $this->viaSql($billing->id);

        $this->assertSame(
            $php->updatedAmount,
            $sql['updated_amount'],
            'Valor atualizado divergiu entre a face PHP e a face SQL.',
        );

        $this->assertSame(
            $php->interestAmount,
            $sql['interest_amount'],
            'Juros divergiram entre a face PHP e a face SQL.',
        );
    }

    // --- a regra em si ------------------------------------------------

    /**
     * O empate não podia ficar só na matriz de consistência: lá as duas faces
     * concordarem bastaria, mesmo que concordassem no valor errado. Aqui o
     * valor está escrito.
     *
     * 4224,10 a 5% por 30 dias = 4435,305, e meio centavo arredonda para cima.
     */
    public function test_empate_no_meio_centavo_arredonda_para_cima(): void
    {
        $this->travelTo(self::HOJE);

        $billing = $this->makeBilling('4224.10', '0.0500', '2026-05-16', null);

        $this->assertSame('4435.31', (new InterestCalculator())->for($billing)->updatedAmount);
        $this->assertSame('4435.31', $this->viaSql($billing->id)['updated_amount']);
    }

    public function test_cobranca_em_dia_nao_acumula_juros(): void
    {
        $this->travelTo(self::HOJE);

        $calculation = (new InterestCalculator())->for(
            $this->makeBilling('1000.00', '0.0200', '2026-06-20', null),
        );

        $this->assertSame('0.00', $calculation->interestAmount);
        $this->assertSame('1000.00', $calculation->updatedAmount);
        $this->assertSame(0, $calculation->daysLate);
    }

    public function test_juros_compostos_conferem_com_a_formula_do_enunciado(): void
    {
        $this->travelTo(self::HOJE);

        // 1000 * (1 + 0.02) ^ (30/30) = 1020.00
        $calculation = (new InterestCalculator())->for(
            $this->makeBilling('1000.00', '0.0200', '2026-05-16', null),
        );

        $this->assertSame(30, $calculation->daysLate);
        $this->assertSame('1020.00', $calculation->updatedAmount);
        $this->assertSame('20.00', $calculation->interestAmount);
    }

    public function test_sessenta_dias_compoem_sobre_o_primeiro_mes(): void
    {
        $this->travelTo(self::HOJE);

        // 1000 * 1.02^2 = 1040.40, e não 1040.00 — a diferença é o composto.
        $calculation = (new InterestCalculator())->for(
            $this->makeBilling('1000.00', '0.0200', '2026-04-16', null),
        );

        $this->assertSame('1040.40', $calculation->updatedAmount);
    }

    public function test_cobranca_paga_usa_os_valores_congelados(): void
    {
        $this->travelTo(self::HOJE);

        $billing = $this->makeBilling('1000.00', '0.0200', '2026-04-10', '2026-05-10');
        $congelado = (new InterestCalculator())->for($billing);

        // Avançar o relógio depois do pagamento é o que dá sentido ao teste:
        // sem isto ele passaria mesmo com a regra errada.
        $this->travelTo('2027-01-01 09:30:00');

        $depois = (new InterestCalculator())->for($billing->fresh());

        $this->assertSame($congelado->updatedAmount, $depois->updatedAmount);
        $this->assertSame($congelado->interestAmount, $depois->interestAmount);
    }

    public function test_cobranca_paga_tambem_congela_na_face_sql(): void
    {
        $this->travelTo(self::HOJE);

        $billing = $this->makeBilling('1000.00', '0.0200', '2026-04-10', '2026-05-10');
        $congelado = $this->viaSql($billing->id);

        $this->travelTo('2027-01-01 09:30:00');

        $this->assertSame($congelado, $this->viaSql($billing->id));
    }

    public function test_cobranca_paga_sem_data_de_pagamento_nao_quebra(): void
    {
        $this->travelTo(self::HOJE);

        $billing = $this->makeBilling('1000.00', '0.0200', '2026-04-10', null);

        // Estado inconsistente que a API não produz — status pago sem data —
        // mas que um import ou uma correção manual no banco pode criar. O
        // ramo defensivo existe para isso, e existir sem teste é o mesmo que
        // não existir.
        DB::table('billings')
            ->where('id', $billing->id)
            ->update(['status' => 'paid', 'payment_date' => null, 'paid_amount' => null]);

        $calculation = (new InterestCalculator())->for($billing->fresh());

        $this->assertSame(0, $calculation->daysLate);
        $this->assertSame('1000.00', $calculation->updatedAmount);
        $this->assertSame('0.00', $calculation->interestAmount);
    }

    // --- helpers ------------------------------------------------------

    private function makeBilling(
        string $amount,
        string $rate,
        string $dueDate,
        ?string $paymentDate,
    ): Billing {
        $billing = Billing::factory()->create([
            'original_amount' => $amount,
            'monthly_interest_rate' => $rate,
            'issue_date' => '2026-01-01',
            'due_date' => $dueDate,
        ]);

        if ($paymentDate !== null) {
            // Passa pelo mesmo serviço que a API usa: congelar à mão aqui
            // faria o teste validar um congelamento que não é o de produção.
            app(RegisterPayment::class)($billing, $paymentDate);
        }

        return $billing->fresh();
    }

    /**
     * @return array{updated_amount: string, interest_amount: string}
     */
    private function viaSql(int $id): array
    {
        $calculator = new InterestCalculator();

        $row = DB::table('billings')
            ->selectRaw("{$calculator->updatedAmountSql()} as updated_amount")
            ->selectRaw("{$calculator->interestAmountSql()} as interest_amount")
            ->where('id', $id)
            ->first();

        return [
            'updated_amount' => number_format((float) $row->updated_amount, 2, '.', ''),
            'interest_amount' => number_format((float) $row->interest_amount, 2, '.', ''),
        ];
    }
}

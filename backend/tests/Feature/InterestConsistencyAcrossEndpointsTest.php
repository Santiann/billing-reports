<?php

namespace Tests\Feature;

use App\Models\Billing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A especificação exige que o valor calculado seja "consistente em todas as telas
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
            'within term' => ['1000.00', '0.0200', -10],
            'overdue by 1 day' => ['1000.00', '0.0200', 1],
            'overdue by 45 days' => ['1234.57', '0.0333', 45],
            'overdue by 400 days' => ['987654.31', '0.0250', 400],
            'zero rate' => ['500.00', '0.0000', 90],
            'minimum cent' => ['0.01', '0.1500', 365],
        ];
    }

    #[DataProvider('cenarios')]
    public function test_the_three_endpoints_return_the_same_amount(
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
        $report = $this->getJson('/api/reports/billings')->assertOk()->json('data.0');

        foreach (['updated_amount', 'interest_amount'] as $field) {
            $this->assertSame(
                $isolada[$field],
                $listagem[$field],
                "Cobrança isolada e listagem divergiram em {$field}.",
            );

            $this->assertSame(
                $isolada[$field],
                $report[$field],
                "Cobrança isolada e relatório divergiram em {$field}.",
            );
        }
    }

    public function test_the_report_total_matches_the_sum_of_the_rows(): void
    {
        $this->travelTo(self::HOJE);
        Sanctum::actingAs(User::factory()->create());

        Billing::factory()->count(4)->overdue(30)->create([
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
        ]);

        $response = $this->getJson('/api/reports/billings?per_page=100')->assertOk();

        // O totalizador vem de uma consulta de agregação separada. Somar as
        // linhas exibidas e comparar é o único jeito de provar que as duas
        // consultas falam do mesmo conjunto e da mesma regra.
        $somaDasLinhas = array_sum(array_map(
            fn (array $row) => (float) $row['updated_amount'],
            $response->json('data'),
        ));

        $this->assertSame(
            number_format($somaDasLinhas, 2, '.', ''),
            $response->json('totals.updated_amount'),
        );
    }

    public function test_a_payment_on_the_exact_due_date_generates_no_interest(): void
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

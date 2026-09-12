<?php

namespace Tests\Feature;

use App\Domain\Billing\RegisterPayment;
use App\Models\Billing;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * O dashboard agrega no banco, e é isso que os testes precisam fixar.
 *
 * Volume aqui é pequeno de propósito: o que se afirma é a REGRA — de onde vem
 * cada número e o que ele inclui. A prova de que o dashboard responde em tempo
 * é medição contra os dois milhões, fora da suíte.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    /** Congelado: o mês corrente precisa significar o mesmo amanhã. */
    private const HOJE = '2026-09-12 10:00:00';

    private function actingAsUser(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_dashboard_exige_autenticacao(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_indicadores_cobrem_o_mes_corrente_por_vencimento(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $customer = Customer::factory()->create();

        // Dentro do mês: duas cobranças.
        Billing::factory()->create([
            'customer_id' => $customer->id,
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-08-10',
            'due_date' => '2026-09-10',
        ]);
        Billing::factory()->create([
            'customer_id' => $customer->id,
            'original_amount' => '500.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-08-20',
            'due_date' => '2026-09-20',
        ]);

        // Fora do mês: não pode entrar em nenhum indicador.
        Billing::factory()->create([
            'customer_id' => $customer->id,
            'original_amount' => '9999.00',
            'issue_date' => '2026-07-01',
            'due_date' => '2026-08-01',
        ]);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('period.count', 2)
            ->assertJsonPath('period.original_amount', '1500.00');
    }

    /**
     * A cobrança vencida do mês acumula juros; a que ainda vai vencer, não.
     * 1000 a 2% com 2 dias de atraso, e 500 com vencimento daqui a 8 dias.
     */
    public function test_juros_do_periodo_somam_apenas_as_vencidas(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        Billing::factory()->create([
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-08-10',
            'due_date' => '2026-09-10',
        ]);
        Billing::factory()->create([
            'original_amount' => '500.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-08-20',
            'due_date' => '2026-09-20',
        ]);

        $resposta = $this->getJson('/api/dashboard')->assertOk();

        $this->assertSame(1, $resposta->json('period.overdue_count'));

        // 1000 * 1.02^(2/30) = 1001.32 -> 1,32 de juros. A de 500 não entra.
        $this->assertSame('1.32', $resposta->json('period.interest_amount'));
    }

    /**
     * Recebido vem das colunas congeladas, nunca de recálculo — é a mesma
     * regra do relatório, e o dashboard não pode discordar dele.
     */
    public function test_recebido_vem_das_colunas_congeladas(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $billing = Billing::factory()->create([
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-08-10',
            'due_date' => '2026-09-10',
        ]);

        // Paga com 2 dias de atraso: 1000 * 1.02^(2/30) = 1001.32.
        app(RegisterPayment::class)($billing, '2026-09-12');

        $resposta = $this->getJson('/api/dashboard')->assertOk();

        $this->assertSame('1001.32', $resposta->json('period.received_amount'));
        // Paga não é vencida, e não entra nos juros a receber.
        $this->assertSame(0, $resposta->json('period.overdue_count'));
        $this->assertSame('0.00', $resposta->json('period.interest_amount'));
    }

    /**
     * Cobrança paga não muda de valor com o tempo. Sem avançar o relógio, o
     * teste passaria mesmo se o dashboard recalculasse.
     */
    public function test_recebido_nao_muda_com_o_tempo(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $billing = Billing::factory()->create([
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-08-10',
            'due_date' => '2026-09-10',
        ]);
        app(RegisterPayment::class)($billing, '2026-09-12');

        $antes = $this->getJson('/api/dashboard')->json('period.received_amount');

        // Ainda dentro do mesmo mês, para o recorte não mudar.
        $this->travelTo('2026-09-30 23:00:00');

        $this->assertSame($antes, $this->getJson('/api/dashboard')->json('period.received_amount'));
    }

    public function test_serie_traz_doze_meses_terminando_no_mes_corrente(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $serie = $this->getJson('/api/dashboard')->assertOk()->json('monthly');

        $this->assertCount(12, $serie);
        $this->assertSame('2025-10', $serie[0]['month']);
        $this->assertSame('2026-09', $serie[11]['month']);
    }

    public function test_serie_separa_recebido_do_que_falta_receber(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $paga = Billing::factory()->create([
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-06-10',
            'due_date' => '2026-07-10',
        ]);
        app(RegisterPayment::class)($paga, '2026-07-10');

        Billing::factory()->create([
            'original_amount' => '400.00',
            'issue_date' => '2026-06-15',
            'due_date' => '2026-07-15',
        ]);

        $julho = collect($this->getJson('/api/dashboard')->json('monthly'))
            ->firstWhere('month', '2026-07');

        $this->assertSame(2, $julho['count']);
        $this->assertSame('1400.00', $julho['original_amount']);
        // Paga em dia: recebeu exatamente o valor original.
        $this->assertSame('1000.00', $julho['received_amount']);
    }

    /**
     * A consulta da série soma `paid_amount` direto, sem filtrar por `status`.
     * Isso só dá o número certo porque as duas coisas são equivalentes, e é
     * uma invariante que nada no schema garante: é o RegisterPayment que a
     * mantém. Este teste é o que impede alguém de quebrá-la sem perceber e
     * fazer o dashboard passar a contar cobrança pendente como recebida.
     */
    public function test_valor_pago_existe_se_e_somente_se_a_cobranca_esta_paga(): void
    {
        $this->travelTo(self::HOJE);

        Billing::factory()->count(3)->create();
        $paga = Billing::factory()->create();
        app(RegisterPayment::class)($paga, '2026-09-10');

        $this->assertSame(
            0,
            Billing::query()->whereNotNull('paid_amount')->where('status', '!=', 'paid')->count(),
        );
        $this->assertSame(
            0,
            Billing::query()->whereNull('paid_amount')->where('status', 'paid')->count(),
        );
    }
}

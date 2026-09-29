<?php

namespace Tests\Feature;

use App\Domain\Billing\RegisterPayment;
use App\Models\Billing;
use App\Models\BillingAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Estorno de pagamento.
 *
 * O estorno desfaz um pagamento que não se sustentou — cheque devolvido,
 * transferência revertida, baixa lançada na cobrança errada. Três regras:
 *
 *   a cobrança volta a pendente, sem os valores de pagamento
 *   os valores congelados do pagamento estornado ficam na trilha
 *   os juros voltam a correr desde o vencimento ORIGINAL
 */
class PaymentReversalTest extends TestCase
{
    use RefreshDatabase;

    /** Quatro dias depois do vencimento: 1000 * 1.02^(4/30) = 1.002,64. */
    private const PAGAMENTO = '2026-05-20 10:00:00';

    /** Trinta dias depois do vencimento: 1000 * 1.02 = 1.020,00. */
    private const HOJE = '2026-06-15 09:30:00';

    private function comoAdmin(): User
    {
        $user = User::factory()->create(['name' => 'Marina Costa']);
        Sanctum::actingAs($user);

        return $user;
    }

    private function billing(): Billing
    {
        return Billing::factory()->create([
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-04-16',
            'due_date' => '2026-05-16',
        ]);
    }

    /** Paga em 20/05 pelo serviço de produção, e volta o relógio para hoje. */
    private function paidBilling(): Billing
    {
        $this->travelTo(self::PAGAMENTO);
        $billing = $this->billing();
        app(RegisterPayment::class)($billing);
        $this->travelTo(self::HOJE);

        return $billing->fresh();
    }

    /**
     * Cabeçalho por requisição, e não `withHeaders()`.
     *
     * `withHeaders()` guarda o cabeçalho para TODAS as requisições seguintes do
     * teste. Aqui isso é fatal: a chave do primeiro pagamento vazava para o
     * estorno, que tem outro caminho, e o middleware respondia 422 de chave
     * reaproveitada — a requisição "sem chave" levava a chave de outra.
     *
     * @param  array<string, string>  $headers
     */
    private function reverse(Billing $billing, array $headers = [])
    {
        return $this->postJson("/api/billings/{$billing->id}/reversal", [], $headers);
    }

    /** @param array<string, string> $headers */
    private function pay(Billing $billing, array $headers = [])
    {
        return $this->postJson("/api/billings/{$billing->id}/payment", [], $headers);
    }

    // --- a cobrança volta a pendente ----------------------------------

    public function test_a_reversal_returns_the_billing_to_pending(): void
    {
        $billing = $this->paidBilling();
        $this->comoAdmin();

        $this->reverse($billing)
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payment_date', null)
            ->assertJsonPath('data.paid_amount', null)
            ->assertJsonPath('data.paid_interest_amount', null);

        $this->assertSame('pending', $billing->fresh()->status->value);
        $this->assertNull($billing->fresh()->paid_amount);
    }

    public function test_only_a_paid_billing_can_be_reversed(): void
    {
        $this->travelTo(self::HOJE);
        $this->comoAdmin();

        $this->reverse($this->billing())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    // --- os valores congelados não somem ------------------------------

    /**
     * As colunas de pagamento são limpas, e o que foi pago continua escrito
     * na trilha: é o `from` da entrada de estorno. A entrada do pagamento
     * original também fica, intacta.
     */
    public function test_the_reversed_payment_amounts_stay_in_the_trail(): void
    {
        $billing = $this->paidBilling();
        $this->comoAdmin();

        $this->assertSame('1002.64', $billing->paid_amount);

        $this->reverse($billing)->assertOk();

        $this->getJson("/api/billings/{$billing->id}/audit")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.event', 'reversed')
            ->assertJsonPath('data.0.event_label', 'Pagamento estornado')
            ->assertJsonPath('data.0.user.name', 'Marina Costa')
            ->assertJsonPath('data.0.changes', [
                ['field' => 'status', 'label' => 'Status', 'from' => 'paid', 'to' => 'pending'],
                ['field' => 'payment_date', 'label' => 'Data do pagamento', 'from' => '2026-05-20', 'to' => null],
                ['field' => 'paid_amount', 'label' => 'Valor pago', 'from' => '1002.64', 'to' => null],
                ['field' => 'paid_interest_amount', 'label' => 'Juros no pagamento', 'from' => '2.64', 'to' => null],
            ])
            ->assertJsonPath('data.1.event', 'paid');
    }

    // --- os juros voltam a correr -------------------------------------

    /**
     * Desde o vencimento original, e não de outra data.
     *
     * Havia três candidatas, e os números mostram a diferença em 15/06:
     *
     *   desde o vencimento (16/05), 30 dias   -> 1.020,00   <- a regra
     *   desde o pagamento  (20/05), 26 dias   -> 1.017,31
     *   desde o estorno    (15/06),  0 dias   -> 1.000,00
     *
     * O pagamento que não se sustentou não aconteceu para o devedor: ele
     * continua devendo desde o vencimento, e o estorno não pode virar desconto.
     */
    public function test_a_reversed_billing_accrues_interest_again_from_the_original_due_date(): void
    {
        $billing = $this->paidBilling();
        $this->comoAdmin();

        $this->reverse($billing)->assertOk();

        $this->getJson("/api/billings/{$billing->id}")
            ->assertOk()
            ->assertJsonPath('data.is_overdue', true)
            ->assertJsonPath('data.interest_amount', '20.00')
            ->assertJsonPath('data.updated_amount', '1020.00');
    }

    /** A face SQL tem que concordar: a listagem e o relatório calculam no SELECT. */
    public function test_after_a_reversal_the_three_screens_agree(): void
    {
        $billing = $this->paidBilling();
        $this->comoAdmin();

        $this->reverse($billing)->assertOk();

        $isolada = $this->getJson("/api/billings/{$billing->id}")->json('data.updated_amount');
        $listagem = $this->getJson('/api/billings')->json('data.0.updated_amount');
        $report = $this->getJson('/api/reports/billings')->json('data.0.updated_amount');

        $this->assertSame('1020.00', $isolada);
        $this->assertSame($isolada, $listagem);
        $this->assertSame($isolada, $report);
    }

    public function test_a_reversed_billing_can_be_paid_again_with_the_interest_of_the_new_date(): void
    {
        $billing = $this->paidBilling();
        $this->comoAdmin();

        $this->reverse($billing)->assertOk();

        $this->pay($billing)
            ->assertOk()
            ->assertJsonPath('data.payment_date', '2026-06-15')
            ->assertJsonPath('data.paid_amount', '1020.00')
            ->assertJsonPath('data.paid_interest_amount', '20.00');

        $this->getJson("/api/billings/{$billing->id}/audit")
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.event', 'paid')
            ->assertJsonPath('data.1.event', 'reversed')
            ->assertJsonPath('data.2.event', 'paid');
    }

    // --- estorno e idempotência ---------------------------------------

    /**
     * A chave do pagamento continua valendo depois do estorno, e isso é o
     * certo.
     *
     * Ela descreve a OPERAÇÃO de pagar, que aconteceu. Um retry atrasado dessa
     * requisição — chegando depois do estorno — recebe o resultado original em
     * vez de pagar de novo. Invalidar a chave no estorno transformaria esse
     * retry exatamente no pagamento em dobro que a chave existe para impedir.
     *
     * Tudo acontece no mesmo dia: a chave vale 24 horas, e viajar de maio a
     * junho a venceria — o teste passaria pelo motivo errado.
     */
    public function test_replaying_the_payment_key_after_a_reversal_does_not_pay_again(): void
    {
        $this->travelTo(self::HOJE);
        $this->comoAdmin();
        $billing = $this->billing();
        $key = ['Idempotency-Key' => 'c2b6f0d4-1e7a-4f58-9c3d-5a8e2b7f4c10'];

        $this->pay($billing, $key)->assertOk();

        $this->travelTo('2026-06-15 10:00:00');
        $this->reverse($billing)->assertOk();

        $this->travelTo('2026-06-15 10:30:00');
        $this->pay($billing, $key)
            ->assertOk()
            ->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame('pending', $billing->fresh()->status->value);
        $this->assertSame(2, BillingAudit::query()->where('billing_id', $billing->id)->count());
    }

    /**
     * O estorno também é idempotente, e o caso que justifica é concreto:
     * pagou, estornou, pagou de novo — e o retry atrasado do estorno chega.
     * Sem a chave, ele estornaria o SEGUNDO pagamento, que ninguém pediu para
     * estornar.
     */
    public function test_a_late_reversal_retry_does_not_reverse_the_next_payment(): void
    {
        $this->travelTo(self::HOJE);
        $this->comoAdmin();
        $billing = $this->billing();
        $key = ['Idempotency-Key' => '9d41a7c3-6b2e-4a90-8f15-3e7c1b9d2a64'];

        $this->pay($billing)->assertOk();
        $this->reverse($billing, $key)->assertOk();
        $this->pay($billing)->assertOk();

        $this->reverse($billing, $key)
            ->assertOk()
            ->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame('paid', $billing->fresh()->status->value);
        $this->assertSame(3, BillingAudit::query()->where('billing_id', $billing->id)->count());
    }

    // --- atomicidade --------------------------------------------------

    public function test_without_the_trail_the_reversal_does_not_happen(): void
    {
        $billing = $this->paidBilling();
        $this->comoAdmin();

        BillingAudit::creating(fn () => throw new RuntimeException('Falha simulada ao gravar a trilha.'));

        $this->reverse($billing)->assertServerError();

        $this->assertSame('paid', $billing->fresh()->status->value);
        $this->assertSame('1002.64', $billing->fresh()->paid_amount);
    }
}

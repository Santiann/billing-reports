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
 * Payment reversal.
 *
 * A reversal undoes a payment that did not hold up — a bounced cheque, a reversed transfer, a
 * settlement posted against the wrong billing. Three rules:
 *
 *   the billing goes back to pending, without the payment amounts
 *   the reversed payment's frozen amounts stay in the trail
 *   os juros voltam a correr desde o vencimento ORIGINAL
 */
class PaymentReversalTest extends TestCase
{
    use RefreshDatabase;

    /** Four days after the due date: 1000 * 1.02^(4/30) = 1,002.64. */
    private const PAGAMENTO = '2026-05-20 10:00:00';

    /** Thirty days after the due date: 1000 * 1.02 = 1,020.00. */
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

    /** Paid on 20/05 through the production service, then the clock returns to today. */
    private function paidBilling(): Billing
    {
        $this->travelTo(self::PAGAMENTO);
        $billing = $this->billing();
        app(RegisterPayment::class)($billing);
        $this->travelTo(self::HOJE);

        return $billing->fresh();
    }

    /**
     * The header per request, and not `withHeaders()`.
     *
     * `withHeaders()` keeps the header for ALL of the test's following requests. Here that is
     * fatal: the first payment's key leaked into the reversal, which has a different path, and
     * the middleware answered 422 for a reused key — the "keyless" request was carrying
     * another one's key.
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

    // --- the billing goes back to pending -----------------------------

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

    // --- the frozen amounts do not disappear --------------------------

    /**
     * The payment columns are cleared, and what was paid stays written in the trail: it is
     * the reversal entry's `from`. The original payment's entry stays too, untouched.
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
     * From the original due date, and not from some other date.
     *
     * There were three candidates, and the numbers show the difference on 15/06:
     *
     *   desde o vencimento (16/05), 30 dias   -> 1.020,00   <- a regra
     *   desde o pagamento  (20/05), 26 dias   -> 1.017,31
     *   desde o estorno    (15/06),  0 dias   -> 1.000,00
     *
     * A payment that did not hold up did not happen as far as the debtor is concerned: they
     * still owe from the due date, and a reversal must not turn into a discount.
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

    /** The SQL face has to agree: the listing and the report compute in the SELECT. */
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
     * The payment's key stays valid after the reversal, and that is the
     * certo.
     *
     * It describes the ACT of paying, which happened. A late retry of that request — arriving
     * after the reversal — receives the original result instead of paying again. Invalidating
     * the key on reversal would turn that retry into exactly the double payment the key exists
     * to prevent.
     *
     * Everything happens on the same day: the key is valid for 24 hours, and travelling from
     * May to June would expire it — the test would pass for the wrong reason.
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
     * The reversal is idempotent too, and the case that justifies it is concrete: paid,
     * reversed, paid again — and then the reversal's late retry arrives. Without the key, it
     * would reverse the SECOND payment, which nobody asked to
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

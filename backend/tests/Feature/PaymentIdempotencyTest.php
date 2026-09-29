<?php

namespace Tests\Feature;

use App\Domain\Billing\BillingStatus;
use App\Models\Billing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Idempotency when recording a payment.
 *
 * The problem is concrete: the user clicks twice, or the browser repeats the request after a
 * network drop. Without a key, the second call finds the billing already paid and answers 422 —
 * which is correct for someone trying to pay again, and a lie for someone who merely repeated
 * the same operation.
 *
 * With a key, the second call returns THE FIRST ONE'S RESULT. The distinction between
 * "repeated" and "tried to pay twice" is what these tests are about.
 */
class PaymentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private const HOJE = '2026-06-15 09:30:00';

    private const CHAVE = '6f1b2c4a-9e77-4d2f-9a0a-1c3b5d7e9f11';

    private function actingAsUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function overdueBilling(): Billing
    {
        // Vencida há 30 dias a 2% ao mês: 1000 * 1.02 = 1020,00.
        return Billing::factory()->create([
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-04-16',
            'due_date' => '2026-05-16',
        ]);
    }

    /** @param array<string, mixed> $body */
    private function pay(Billing $billing, ?string $key, array $body = [])
    {
        return $this->withHeaders($key === null ? [] : ['Idempotency-Key' => $key])
            ->postJson("/api/billings/{$billing->id}/payment", $body);
    }

    // --- the case that motivates all of it ----------------------------

    public function test_a_second_call_with_the_same_key_returns_the_first_result(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();
        $billing = $this->overdueBilling();

        $firstOne = $this->pay($billing, self::CHAVE)->assertOk();
        $segunda = $this->pay($billing, self::CHAVE)->assertOk();

        // The same body, byte for byte: it is the stored result, not a recompute.
        $this->assertSame($firstOne->json(), $segunda->json());
        $this->assertSame('1020.00', $segunda->json('data.paid_amount'));
    }

    public function test_a_double_click_does_not_create_two_payments(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();
        $billing = $this->overdueBilling();

        $this->pay($billing, self::CHAVE)->assertOk();
        $this->pay($billing, self::CHAVE)->assertOk();

        $billing->refresh();

        $this->assertSame(BillingStatus::Paid, $billing->status);
        $this->assertSame('1020.00', $billing->paid_amount);
        $this->assertSame('20.00', $billing->paid_interest_amount);
    }

    /**
     * A replay must not recompute: if the second request reprocessed, the interest would
     * freeze at the SECOND call's date.
     */
    public function test_a_replay_does_not_recompute_the_interest(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();
        $billing = $this->overdueBilling();

        $firstOne = $this->pay($billing, self::CHAVE)->assertOk();

        // Twenty-three hours later — within the key's validity, and already the next day:
        // recomputing would give 31 days late, R$ 1,020.67.
        $this->travelTo('2026-06-16 08:30:00');
        $segunda = $this->pay($billing, self::CHAVE)->assertOk();

        $this->assertSame('1020.00', $firstOne->json('data.paid_amount'));
        $this->assertSame('1020.00', $segunda->json('data.paid_amount'));
        $this->assertSame('2026-06-15', $billing->refresh()->payment_date->toDateString());
    }

    // --- what does NOT change -----------------------------------------

    /**
     * Without a key the old behaviour stands: whoever tries to pay an already paid billing
     * gets a 422. Idempotency is for whoever repeats the SAME operation, not for turning an
     * error into a success.
     */
    public function test_without_a_key_the_second_attempt_is_still_refused(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();
        $billing = $this->overdueBilling();

        $this->pay($billing, null)->assertOk();
        $this->pay($billing, null)->assertStatus(422);
    }

    /** A new key against an already paid billing is also a 422: it is a different operation. */
    public function test_a_different_key_against_a_paid_billing_is_refused(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();
        $billing = $this->overdueBilling();

        $this->pay($billing, self::CHAVE)->assertOk();
        $this->pay($billing, 'outra-chave-completamente-diferente')->assertStatus(422);
    }

    /** The error is stored too: replaying a call that failed replays the failure. */
    public function test_the_error_response_is_replayed_too(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();
        $billing = $this->overdueBilling();

        // A future date is refused by validation.
        $body = ['payment_date' => '2027-01-01'];

        $firstOne = $this->pay($billing, self::CHAVE, $body)->assertStatus(422);
        $segunda = $this->pay($billing, self::CHAVE, $body)->assertStatus(422);

        $this->assertSame($firstOne->json(), $segunda->json());
    }

    // --- misusing the key ---------------------------------------------

    /**
     * The same key with a different payload is the caller's bug, and answering the
     * resultado antigo esconderia o bug. O 422 nomeia o problema.
     */
    public function test_the_same_key_with_a_different_payload_is_refused(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();
        $billing = $this->overdueBilling();

        $this->pay($billing, self::CHAVE, ['paid_amount' => '1000.00'])->assertOk();

        $this->pay($billing, self::CHAVE, ['paid_amount' => '999.00'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains(mb_strtolower($m), 'chave'));
    }

    public function test_the_same_key_on_different_billings_is_refused(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $this->pay($this->overdueBilling(), self::CHAVE)->assertOk();
        $this->pay($this->overdueBilling(), self::CHAVE)->assertStatus(422);
    }

    /** The key belongs to whoever used it: another user with the same key is not a replay. */
    public function test_the_key_is_scoped_per_user(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();
        $billing = $this->overdueBilling();

        $this->pay($billing, self::CHAVE)->assertOk();

        // Another user, the same key: they do not get the first one's stored response — they
        // get the 422 for an already paid billing, which is the truth.
        $this->actingAsUser();
        $this->pay($billing, self::CHAVE)->assertStatus(422);
    }

    /**
     * Two calls at the same time: the second finds the key reserved and still without a
     * response. Answering 409 is what stops both from processing.
     */
    public function test_a_concurrent_call_with_the_same_key_responds_409(): void
    {
        $this->travelTo(self::HOJE);
        $user = $this->actingAsUser();
        $billing = $this->overdueBilling();

        // Simulates the first request still in flight: the key is reserved and the response
        // has not been written yet.
        DB::table('idempotency_keys')->insert([
            'user_id' => $user->id,
            'key' => self::CHAVE,
            'fingerprint' => hash('sha256', 'qualquer'),
            'response_status' => null,
            'response_body' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->pay($billing, self::CHAVE)->assertStatus(409);
    }

    /** An expired key is a new key: storing a response forever is not an option. */
    public function test_an_expired_key_does_not_replay_the_response(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();
        $billing = $this->overdueBilling();

        $this->pay($billing, self::CHAVE)->assertOk();

        // Once the deadline has passed, the key is no longer valid and the billing is already paid.
        $this->travelTo('2026-06-17 09:30:00');
        $this->pay($billing, self::CHAVE)->assertStatus(422);
    }
}

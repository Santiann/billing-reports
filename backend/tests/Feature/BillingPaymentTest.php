<?php

namespace Tests\Feature;

use App\Domain\Billing\BillingStatus;
use App\Models\Billing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const HOJE = '2026-06-15 09:30:00';

    private function actingAsUser(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_recording_a_payment_requires_authentication(): void
    {
        $billing = Billing::factory()->create();

        $this->postJson("/api/billings/{$billing->id}/payment")->assertUnauthorized();
    }

    public function test_records_a_payment_and_freezes_the_interest(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        // Vencida há 30 dias a 2% ao mês: 1000 * 1.02^1 = 1020.00.
        $billing = Billing::factory()->create([
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-04-16',
            'due_date' => '2026-05-16',
        ]);

        $this->postJson("/api/billings/{$billing->id}/payment")
            ->assertOk()
            ->assertJsonPath('data.status', BillingStatus::Paid->value);

        $billing->refresh();

        $this->assertSame('2026-06-15', $billing->payment_date->toDateString());
        $this->assertSame('20.00', $billing->paid_interest_amount);
        $this->assertSame('1020.00', $billing->paid_amount);
    }

    public function test_a_paid_billing_stops_accruing_interest(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $billing = Billing::factory()->create([
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-04-16',
            'due_date' => '2026-05-16',
        ]);

        $this->postJson("/api/billings/{$billing->id}/payment")->assertOk();

        $noPagamento = $this->getJson("/api/billings/{$billing->id}")
            ->assertOk()
            ->json('data');

        // Moving the clock forward six months is what gives the test meaning: without it, it
        // would pass even if the rule recomputed interest on a paid billing.
        $this->travelTo('2026-12-15 09:30:00');

        $seisMesesDepois = $this->getJson("/api/billings/{$billing->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame($noPagamento['updated_amount'], $seisMesesDepois['updated_amount']);
        $this->assertSame($noPagamento['interest_amount'], $seisMesesDepois['interest_amount']);
        $this->assertSame('1020.00', $seisMesesDepois['updated_amount']);
    }

    public function test_interest_is_computed_at_the_payment_date_and_not_today(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $billing = Billing::factory()->create([
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-04-16',
            'due_date' => '2026-05-16',
        ]);

        // A backdated payment: 15 days late, not 30.
        $this->postJson("/api/billings/{$billing->id}/payment", [
            'payment_date' => '2026-05-31',
        ])->assertOk();

        $billing->refresh();

        // 1000 * 1.02^(15/30) = 1009.95
        $this->assertSame('1009.95', $billing->paid_amount);
        $this->assertSame('9.95', $billing->paid_interest_amount);
    }

    public function test_a_billing_within_term_is_paid_without_interest(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $billing = Billing::factory()->create([
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-06-01',
            'due_date' => '2026-06-30',
        ]);

        $this->postJson("/api/billings/{$billing->id}/payment")->assertOk();

        $billing->refresh();

        $this->assertSame('0.00', $billing->paid_interest_amount);
        $this->assertSame('1000.00', $billing->paid_amount);
    }

    public function test_a_supplied_paid_amount_overrides_the_computed_one(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $billing = Billing::factory()->overdue(30)->create([
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
        ]);

        // A settlement, a discount: the amount actually received may differ.
        $this->postJson("/api/billings/{$billing->id}/payment", [
            'paid_amount' => '1000.00',
        ])->assertOk();

        $billing->refresh();

        $this->assertSame('1000.00', $billing->paid_amount);
        // The computed interest stays recorded, even with the discount.
        $this->assertSame('20.00', $billing->paid_interest_amount);
    }

    public function test_an_already_paid_billing_cannot_be_paid_again(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $billing = Billing::factory()->paid()->create();

        $this->postJson("/api/billings/{$billing->id}/payment")->assertUnprocessable();
    }

    public function test_a_payment_date_in_the_future_is_rejected(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $billing = Billing::factory()->create();

        $this->postJson("/api/billings/{$billing->id}/payment", [
            'payment_date' => '2026-06-16',
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_date');
    }

    public function test_a_payment_before_the_issue_date_is_rejected(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $billing = Billing::factory()->create(['issue_date' => '2026-06-01']);

        $this->postJson("/api/billings/{$billing->id}/payment", [
            'payment_date' => '2026-05-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_date');
    }

    public function test_the_listing_brings_the_updated_amount_computed_in_sql(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        Billing::factory()->create([
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-04-16',
            'due_date' => '2026-05-16',
        ]);

        $this->getJson('/api/billings')
            ->assertOk()
            ->assertJsonPath('data.0.updated_amount', '1020.00')
            ->assertJsonPath('data.0.interest_amount', '20.00');
    }
}

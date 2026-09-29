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
 * The dashboard aggregates in the database, and that is what these tests have to pin down.
 *
 * Volume here is small on purpose: what gets asserted is the RULE — where each number comes from
 * and what it includes. The proof that the dashboard answers in time is measurement against the
 * two million, outside the suite.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    /** Frozen: the current month has to mean the same thing tomorrow. */
    private const HOJE = '2026-09-12 10:00:00';

    private function actingAsUser(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_the_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_the_indicators_cover_the_current_month_by_due_date(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $customer = Customer::factory()->create();

        // Inside the month: two billings.
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

        // Outside the month: it must not enter any indicator.
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
     * The month's overdue billing accrues interest; the one still to fall due does not.
     * 1000 at 2% with 2 days late, and 500 falling due in 8 days.
     */
    public function test_interest_for_the_period_sums_only_the_overdue_ones(): void
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

        $response = $this->getJson('/api/dashboard')->assertOk();

        $this->assertSame(1, $response->json('period.overdue_count'));

        // 1000 * 1.02^(2/30) = 1001.32 -> 1.32 of interest. The 500 one does not count.
        $this->assertSame('1.32', $response->json('period.interest_amount'));
    }

    /**
     * Received comes from the frozen columns, never from a recompute — it is the report's rule,
     * and the dashboard cannot disagree with it.
     */
    public function test_received_comes_from_the_frozen_columns(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $billing = Billing::factory()->create([
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-08-10',
            'due_date' => '2026-09-10',
        ]);

        // Paid 2 days late: 1000 * 1.02^(2/30) = 1001.32.
        app(RegisterPayment::class)($billing, '2026-09-12');

        $response = $this->getJson('/api/dashboard')->assertOk();

        $this->assertSame('1001.32', $response->json('period.received_amount'));
        // Paid is not overdue, and does not count towards receivable interest.
        $this->assertSame(0, $response->json('period.overdue_count'));
        $this->assertSame('0.00', $response->json('period.interest_amount'));
    }

    /**
     * A paid billing does not change value over time. Without moving the clock forward, the test
     * would pass even if the dashboard recomputed.
     */
    public function test_received_does_not_change_over_time(): void
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

        $before = $this->getJson('/api/dashboard')->json('period.received_amount');

        // Still inside the same month, so the scope does not change.
        $this->travelTo('2026-09-30 23:00:00');

        $this->assertSame($before, $this->getJson('/api/dashboard')->json('period.received_amount'));
    }

    public function test_the_series_brings_twelve_months_ending_in_the_current_one(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $serie = $this->getJson('/api/dashboard')->assertOk()->json('monthly');

        $this->assertCount(12, $serie);
        $this->assertSame('2025-10', $serie[0]['month']);
        $this->assertSame('2026-09', $serie[11]['month']);
    }

    public function test_the_series_separates_received_from_still_outstanding(): void
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
        // Paid on time: exactly the original amount was received.
        $this->assertSame('1000.00', $julho['received_amount']);
    }

    /**
     * The series query sums `paid_amount` directly, without filtering by `status`. That only
     * gives the right number because the two are equivalent, and it is an invariant nothing in the
     * schema guarantees: RegisterPayment is what maintains it. This test is what stops someone
     * breaking it without noticing and having the dashboard start counting a pending billing as
     * received.
     */
    public function test_paid_amount_exists_if_and_only_if_the_billing_is_paid(): void
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

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
 * The test that upholds the requirement of a consistent result across screens and
 * relatório.
 *
 * InterestCalculator has two faces — one in SQL, used in the listing and in the aggregations,
 * and one in PHP, used to display a single billing. They can drift apart silently: rounding,
 * DECIMAL precision against float, day counting. Here the same matrix of cases passes through
 * both and the results are compared down to the cent.
 *
 * It touches the database on purpose: the SQL face only exists inside MySQL. It lives in
 * tests/Unit because the object under test is the calculator, not a route.
 */
class InterestCalculatorTest extends TestCase
{
    use RefreshDatabase;

    /** Frozen: "overdue by 30 days" has to mean the same thing tomorrow. */
    private const HOJE = '2026-06-15 09:30:00';

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string|null}>
     */
    public static function casos(): array
    {
        //        [ amount,     rate,     due date,     payment ]
        return [
            'within term, due tomorrow' => ['1000.00', '0.0200', '2026-06-16', null],
            'due today' => ['1000.00', '0.0200', '2026-06-15', null],
            'overdue by 1 day' => ['1000.00', '0.0200', '2026-06-14', null],
            'overdue by 30 days' => ['1000.00', '0.0200', '2026-05-16', null],
            'overdue by 400 days' => ['1000.00', '0.0200', '2025-05-11', null],
            'zero rate, overdue by 90 days' => ['1000.00', '0.0000', '2026-03-17', null],
            'high rate, overdue by 45 days' => ['1000.00', '0.1500', '2026-05-01', null],
            'broken cents' => ['1234.57', '0.0333', '2026-04-02', null],
            'high amount and odd cent' => ['987654.31', '0.0250', '2026-01-07', null],
            // A case chosen by measurement, not by intuition: a sweep of 900 days x 6 rates
            // x 3 amounts found 78 combinations in which MySQL's DECIMAL division
            // (400/30 = 13.3333, truncated to four places) changes the cent against the
            // double division. This is one of them, and it is the case that guards
            // compoundSql's `/ 30e0`: without it, DECIMAL gives 1363158.13 and PHP gives
            // 1363158.14.
            'decimal vs double divergence' => ['987654.31', '0.0350', '2025-09-07', null],
            // Another case found by sweeping, and of a different nature from the previous
            // one: here the arithmetic lands EXACTLY on the half cent. 4224.10 at 5% for 30
            // days gives 4435.305. PHP rounds half away from zero and gives 4435.31; MySQL's
            // ROUND over a DOUBLE rounds half to even and gives 4435.30. A sweep of 200,000
            // combinations found one such divergence, and the two-million base found another.
            'half cent tie' => ['4224.10', '0.0500', '2026-05-16', null],
            'half cent tie, high amount' => ['435254.90', '0.0500', '2026-05-16', null],
            'paid within term' => ['1500.00', '0.0200', '2026-05-20', '2026-05-18'],
            'paid late' => ['1500.00', '0.0200', '2026-04-10', '2026-05-20'],
        ];
    }

    #[DataProvider('casos')]
    public function test_the_two_faces_return_the_same_amount(
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

    // --- the rule itself ----------------------------------------------

    /**
     * The tie could not live only in the consistency matrix: there it would be enough for the
     * two faces to agree, even if they agreed on the wrong value. Here the value is written
     * down.
     *
     * 4224.10 at 5% for 30 days = 4435.305, and half a cent rounds up.
     */
    public function test_a_half_cent_tie_rounds_up(): void
    {
        $this->travelTo(self::HOJE);

        $billing = $this->makeBilling('4224.10', '0.0500', '2026-05-16', null);

        $this->assertSame('4435.31', (new InterestCalculator())->for($billing)->updatedAmount);
        $this->assertSame('4435.31', $this->viaSql($billing->id)['updated_amount']);
    }

    public function test_a_billing_within_term_accrues_no_interest(): void
    {
        $this->travelTo(self::HOJE);

        $calculation = (new InterestCalculator())->for(
            $this->makeBilling('1000.00', '0.0200', '2026-06-20', null),
        );

        $this->assertSame('0.00', $calculation->interestAmount);
        $this->assertSame('1000.00', $calculation->updatedAmount);
        $this->assertSame(0, $calculation->daysLate);
    }

    public function test_compound_interest_matches_the_specified_formula(): void
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

    public function test_sixty_days_compound_on_top_of_the_first_month(): void
    {
        $this->travelTo(self::HOJE);

        // 1000 * 1.02^2 = 1040.40, and not 1040.00 — the difference is the compounding.
        $calculation = (new InterestCalculator())->for(
            $this->makeBilling('1000.00', '0.0200', '2026-04-16', null),
        );

        $this->assertSame('1040.40', $calculation->updatedAmount);
    }

    public function test_a_paid_billing_uses_the_frozen_amounts(): void
    {
        $this->travelTo(self::HOJE);

        $billing = $this->makeBilling('1000.00', '0.0200', '2026-04-10', '2026-05-10');
        $congelado = (new InterestCalculator())->for($billing);

        // Moving the clock forward after the payment is what gives the test meaning: without
        // it, it would pass even with the rule wrong.
        $this->travelTo('2027-01-01 09:30:00');

        $after = (new InterestCalculator())->for($billing->fresh());

        $this->assertSame($congelado->updatedAmount, $after->updatedAmount);
        $this->assertSame($congelado->interestAmount, $after->interestAmount);
    }

    public function test_a_paid_billing_freezes_on_the_sql_face_too(): void
    {
        $this->travelTo(self::HOJE);

        $billing = $this->makeBilling('1000.00', '0.0200', '2026-04-10', '2026-05-10');
        $congelado = $this->viaSql($billing->id);

        $this->travelTo('2027-01-01 09:30:00');

        $this->assertSame($congelado, $this->viaSql($billing->id));
    }

    public function test_a_paid_billing_with_no_payment_date_does_not_break(): void
    {
        $this->travelTo(self::HOJE);

        $billing = $this->makeBilling('1000.00', '0.0200', '2026-04-10', null);

        // An inconsistent state the API does not produce — paid status with no date — but one
        // an import or a manual fix in the database can create. The defensive branch exists for
        // that, and existing without a test is the same as not existing.
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
            // It goes through the same service the API uses: freezing by hand here would have
            // the test validate a freeze that is not production's.
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

<?php

namespace App\Domain\Billing;

use App\Models\Billing;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The single source of the interest rule.
 *
 * Compound interest: original_amount * (1 + monthly_rate) ^ (days_late / 30).
 *
 * The class has two faces and they have to agree down to the cent:
 *
 *   sqlExpression (updatedAmountSql / interestAmountSql)
 *     Used in selectRaw in the listing and in the aggregations. It is what makes
 *     it possible to SORT by updated amount and SUM interest over the whole
 *     filtered set without loading anything into memory.
 *
 *   for(Billing)
 *     Used to display a single billing.
 *
 * InterestCalculatorTest runs the same matrix of cases through both and asserts
 * they are equal. Without that test the two drift apart in silence.
 */
final class InterestCalculator
{
    private const DAYS_IN_MONTH = 30;

    /**
     * Guard digits before the final rounding.
     *
     * Both faces start from the same floating-point product, but they round a tie
     * differently: PHP rounds half away from zero, and MySQL's ROUND over a DOUBLE
     * rounds half to even. 4224.10 at 5% for 30 days gives exactly 4435.305 — PHP
     * returned 4435.31 and MySQL 4435.30. A sweep of 200,000 combinations found one
     * occurrence, and the two-million base found another: rare, and still the screen
     * disagreeing with the report.
     *
     * Rounding first to six places and only then to two fixes it on both engines,
     * because in both the second rounding then operates on an exact decimal rather
     * than on the double. As a bonus it absorbs a 1 ULP difference between PHP's
     * `pow()` and MySQL's, which run in different containers and need not share the
     * same libm.
     */
    private const GUARD_DIGITS = 6;

    /**
     * The reference date comes down from PHP instead of the SQL face using CURDATE().
     *
     * This is not fussiness: `travelTo()` moves PHP's clock and not MySQL's. With
     * CURDATE() embedded, the consistency test would compare PHP on frozen time
     * against SQL on real time and would never close.
     *
     * It is also what makes it possible to compute interest "at the payment date",
     * which is exactly what freezing needs.
     */
    public function __construct(
        private readonly CarbonInterface|string|null $reference = null,
    ) {}

    /**
     * The date the interest is computed at.
     *
     * Public for the totals cache: the key needs the SAME date the SQL uses, and not
     * a parallel `now()` that could roll over the day between one and the other.
     */
    public function referenceDate(): CarbonImmutable
    {
        return $this->reference === null
            ? CarbonImmutable::now()->startOfDay()
            : CarbonImmutable::parse($this->reference)->startOfDay();
    }

    // --- PHP face -----------------------------------------------------

    public function for(Billing $billing): InterestCalculation
    {
        // A paid billing accrues no interest: the amounts come from the columns
        // written at payment time, never from a recompute. Recomputing would make a
        // billing paid late change value with every day that passes.
        if ($billing->status === BillingStatus::Paid) {
            return new InterestCalculation(
                originalAmount: $this->money($billing->original_amount),
                interestAmount: $this->money($billing->paid_interest_amount ?? '0'),
                updatedAmount: $this->money(
                    $billing->paid_amount ?? $billing->original_amount,
                ),
                daysLate: $billing->payment_date === null
                    ? 0
                    : $this->daysBetween($billing->due_date, $billing->payment_date),
            );
        }

        $original = (float) $billing->original_amount;
        $daysLate = $this->daysBetween($billing->due_date, $this->referenceDate());

        $updated = $daysLate <= 0
            ? $this->round($original)
            : $this->round(
                $original * pow(
                    1 + (float) $billing->monthly_interest_rate,
                    $daysLate / self::DAYS_IN_MONTH,
                ),
            );

        return new InterestCalculation(
            originalAmount: $this->money($original),
            // Subtracts from the ALREADY rounded amount, in the same order as the SQL
            // face: rounding the difference separately would drift by a cent.
            interestAmount: $this->money($updated - $this->round($original)),
            updatedAmount: $this->money($updated),
            daysLate: $daysLate,
        );
    }

    // --- SQL face -----------------------------------------------------

    /**
     * The date goes in as a literal, and that is safe: it is generated here from a
     * Carbon, never taken from the request.
     */
    public function daysLateSql(string $table = 'billings'): string
    {
        $reference = $this->referenceDate()->toDateString();

        return "GREATEST(DATEDIFF('{$reference}', {$table}.due_date), 0)";
    }

    /**
     * "Overdue" in SQL: pending with the due date in the past.
     *
     * It lives here, next to the calculation, because it is the same rule seen from
     * another angle — and for the same reason it uses the date coming from PHP, not
     * CURDATE().
     */
    public function overdueSql(string $table = 'billings'): string
    {
        $reference = $this->referenceDate()->toDateString();

        return "({$table}.status = '".BillingStatus::Pending->value."'"
            ." AND {$table}.due_date < '{$reference}')";
    }

    public function updatedAmountSql(string $table = 'billings'): string
    {
        return "CASE WHEN {$table}.status = '".BillingStatus::Paid->value."'"
            ." THEN COALESCE({$table}.paid_amount, {$table}.original_amount)"
            ." ELSE {$this->compoundSql($table)}"
            .' END';
    }

    public function interestAmountSql(string $table = 'billings'): string
    {
        return "CASE WHEN {$table}.status = '".BillingStatus::Paid->value."'"
            ." THEN COALESCE({$table}.paid_interest_amount, 0)"
            ." ELSE {$this->compoundSql($table)} - {$table}.original_amount"
            .' END';
    }

    /**
     * `/ 30e0` is not style, it is correctness.
     *
     * In MySQL, dividing a DECIMAL returns a DECIMAL truncated to four places by
     * default: 400 / 30 becomes 13.3333, whereas in PHP it is 13.333333…. With
     * different exponents, POW returns different values and the two faces drift
     * apart. The literal `30e0` is a double and forces the division to become one.
     */
    private function compoundSql(string $table): string
    {
        // CAST to DECIMAL before the ROUND, rather than rounding directly: MySQL's
        // ROUND over a DOUBLE rounds half to even, while PHP rounds half away from
        // zero. Converted to an exact decimal with the guard digits, the final
        // rounding agrees on both.
        return 'ROUND(CAST('
            ."{$table}.original_amount * POW("
            ."1 + {$table}.monthly_interest_rate, "
            .'('.$this->daysLateSql($table).') / '.self::DAYS_IN_MONTH.'e0'
            .') AS DECIMAL(20, '.self::GUARD_DIGITS.')), 2)';
    }

    // --- helpers ------------------------------------------------------

    private function daysBetween(CarbonInterface $due, CarbonInterface|string $target): int
    {
        // CarbonImmutable so startOfDay() does not mutate the model's date.
        $from = CarbonImmutable::parse($due)->startOfDay();
        $to = CarbonImmutable::parse($target)->startOfDay();

        return max(0, (int) $from->diffInDays($to));
    }

    /** The rule's rounding: guard digits first, the cent afterwards. */
    private function round(float $value): float
    {
        return round(round($value, self::GUARD_DIGITS), 2);
    }

    private function money(string|float $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}

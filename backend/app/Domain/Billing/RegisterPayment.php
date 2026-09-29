<?php

namespace App\Domain\Billing;

use App\Models\Billing;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Freezes the interest at the moment of payment.
 *
 * The interest is computed at the PAYMENT DATE, not at today: paying a billing with a
 * backdated date has to produce that day's amount. That is why InterestCalculator accepts a
 * reference date.
 *
 * After here the billing stops accruing: the displayed amount comes from the stored columns,
 * and InterestCalculator returns those instead of recomputing.
 */
final class RegisterPayment
{
    public function __invoke(
        Billing $billing,
        CarbonInterface|string|null $paymentDate = null,
        ?string $paidAmount = null,
    ): Billing {
        // In a transaction: the trail writes the payment inside it, and a payment with no
        // record in the trail does not stick.
        $billing->updateOrFail($this->freeze($billing, $paymentDate, $paidAmount));

        return $billing;
    }

    /**
     * The columns a payment writes, without writing them.
     *
     * It exists separately because of the volume seeder: it needs the same frozen amounts for
     * millions of rows going in through batch inserts, and one UPDATE per billing would
     * destroy the load. With this it builds the row by the production rule, without rewriting
     * the formula.
     *
     * It returns scalars, and not an enum and a Carbon, because both consumers eat the same
     * array: Eloquent's `update()`, which casts on the way in, and the seeder's raw insert,
     * which does not.
     *
     * @return array<string, string>
     */
    public function freeze(
        Billing $billing,
        CarbonInterface|string|null $paymentDate = null,
        ?string $paidAmount = null,
    ): array {
        $date = CarbonImmutable::parse(
            $paymentDate ?? CarbonImmutable::now(),
        )->startOfDay();

        $calculation = (new InterestCalculator($date))->for($billing);

        return [
            'status' => BillingStatus::Paid->value,
            'payment_date' => $date->toDateString(),
            'paid_interest_amount' => $calculation->interestAmount,
            // The amount actually paid may differ from the computed one (a settlement, a
            // discount). When not supplied, the updated amount is assumed.
            'paid_amount' => $paidAmount ?? $calculation->updatedAmount,
        ];
    }
}

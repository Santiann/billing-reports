<?php

namespace App\Domain\Billing;

use App\Models\Billing;

/**
 * Reverses the payment: the billing goes back to pending.
 *
 * Clearing the payment columns is all a reversal has to do, and that is why it is small. The
 * interest starts running again from the original due date with no new rule:
 * InterestCalculator only reads the frozen columns when the billing is paid, and a pending
 * billing is computed from its due date — on both faces, PHP and SQL.
 *
 * The amounts that leave here are not lost: the audit trail writes them into the reversal
 * entry's `from`, inside the same transaction.
 */
final class ReversePayment
{
    public function __invoke(Billing $billing): Billing
    {
        $billing->updateOrFail([
            'status' => BillingStatus::Pending->value,
            'payment_date' => null,
            'paid_amount' => null,
            'paid_interest_amount' => null,
        ]);

        return $billing;
    }
}

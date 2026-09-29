<?php

namespace App\Domain\Billing;

/**
 * A billing's stored status.
 *
 * "Overdue" is deliberately absent: it is a derivable condition (`Pending` + due date in the
 * past), not a stored state. See the comment in the billings migration.
 */
enum BillingStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Paid => 'Paga',
        };
    }
}

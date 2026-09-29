<?php

namespace App\Domain\Billing\Audit;

use App\Domain\Billing\BillingStatus;

enum BillingAuditEvent: string
{
    case Updated = 'updated';
    case Paid = 'paid';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Updated => 'Editada',
            self::Paid => 'Pagamento registrado',
            self::Reversed => 'Pagamento estornado',
        };
    }

    /**
     * The event comes from the status TRANSITION, not from the caller.
     *
     * No point in the code declares "this is a payment": pending becoming paid is a
     * payment, paid going back to pending is a reversal, wherever it comes from. That way
     * no new path can label it wrongly.
     */
    public static function fromTransition(?BillingStatus $before, ?BillingStatus $after): self
    {
        return match (true) {
            $before === BillingStatus::Pending && $after === BillingStatus::Paid => self::Paid,
            $before === BillingStatus::Paid && $after === BillingStatus::Pending => self::Reversed,
            default => self::Updated,
        };
    }
}

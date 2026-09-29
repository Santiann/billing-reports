<?php

namespace App\Domain\Billing\Audit;

use App\Models\Billing;
use App\Models\BillingAudit;
use BackedEnum;
use Carbon\CarbonInterface;

/**
 * Writes the trail on every change to a billing.
 *
 * An observer, and not an explicit call at each point that changes something: the trail has
 * to be complete, and an explicit call is the kind of thing the next write path forgets.
 * Here every change through Eloquent gets in, whether it comes from the controller, from
 * recording a payment, or from tinker.
 *
 * The price is that a raw query slips past without warning. That is why there is a test that
 * sweeps `app/` looking for a direct `update` on `billings`.
 */
final class BillingAuditObserver
{
    /** Eloquent's own stamps: they change on every write and say nothing. */
    private const IGNORED = ['created_at', 'updated_at'];

    /**
     * `updated`, and not `updating`: here the id exists and the write has already gone
     * through the database, while the original has not been synced yet — `getOriginal()`
     * still returns the previous value.
     *
     * Writing the trail happens inside the transaction of whoever made the change
     * (`updateOrFail`). If it fails, the change rolls back with it.
     */
    public function updated(Billing $billing): void
    {
        $changes = [];

        foreach (array_keys($billing->getChanges()) as $field) {
            if (in_array($field, self::IGNORED, true)) {
                continue;
            }

            $changes[$field] = [
                'from' => $this->toScalar($billing->getOriginal($field)),
                'to' => $this->toScalar($billing->getAttribute($field)),
            ];
        }

        if ($changes === []) {
            return;
        }

        BillingAudit::create([
            'billing_id' => $billing->id,
            // Null outside a request: console, artisan command.
            'user_id' => auth()->id(),
            'event' => BillingAuditEvent::fromTransition(
                $billing->getOriginal('status'),
                $billing->status,
            ),
            'changes' => $changes,
        ]);
    }

    /**
     * The value as the database stores it, so the trail does not depend on the casts.
     *
     * The values pass through the model's casts before arriving here, and that is what makes
     * "1000" and "1000.00" the same number: Eloquent only marks as changed what the cast
     * considers different.
     */
    private function toScalar(mixed $value): string|int|float|null
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof CarbonInterface => $value->toDateString(),
            default => $value,
        };
    }
}

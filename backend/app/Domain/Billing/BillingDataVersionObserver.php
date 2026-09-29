<?php

namespace App\Domain\Billing;

use App\Models\Billing;

/**
 * Bumps the data version on every write to a billing through Eloquent.
 *
 * `updated`, and not `saved`: `saved` fires even when nothing changed, and an edit that
 * alters nothing would invalidate the cache for no reason.
 *
 * What does not go through Eloquent — the import, which writes in batches, and the volume
 * seeder — bumps the version on its own.
 */
final class BillingDataVersionObserver
{
    public function __construct(private readonly BillingDataVersion $version) {}

    public function created(Billing $billing): void
    {
        $this->version->bump();
    }

    public function updated(Billing $billing): void
    {
        $this->version->bump();
    }

    public function deleted(Billing $billing): void
    {
        $this->version->bump();
    }
}

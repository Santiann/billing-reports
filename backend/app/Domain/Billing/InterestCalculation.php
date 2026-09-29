<?php

namespace App\Domain\Billing;

/**
 * The calculation's result, with the amounts already as two-digit decimal strings.
 *
 * Strings and not floats: it is the format the amount travels through the API and reaches the
 * screen in, and converting to float along the way is where the cent gets lost.
 */
final class InterestCalculation
{
    public function __construct(
        public readonly string $originalAmount,
        public readonly string $interestAmount,
        public readonly string $updatedAmount,
        public readonly int $daysLate,
    ) {}
}

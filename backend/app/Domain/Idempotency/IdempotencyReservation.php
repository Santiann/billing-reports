<?php

namespace App\Domain\Idempotency;

/**
 * The reservation's outcome, with the stored response when there is one.
 */
final readonly class IdempotencyReservation
{
    public function __construct(
        public IdempotencyOutcome $outcome,
        public ?int $status = null,
        public ?string $body = null,
    ) {}
}

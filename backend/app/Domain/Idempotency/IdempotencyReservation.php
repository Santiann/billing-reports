<?php

namespace App\Domain\Idempotency;

/**
 * O desfecho da reserva, com a resposta guardada quando há uma.
 */
final readonly class IdempotencyReservation
{
    public function __construct(
        public IdempotencyOutcome $outcome,
        public ?int $status = null,
        public ?string $body = null,
    ) {}
}

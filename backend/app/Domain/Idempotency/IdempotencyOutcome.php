<?php

namespace App\Domain\Idempotency;

/**
 * The four possible outcomes of reserving a key.
 *
 * There are four because "the key already exists" is not one situation, and treating it as one
 * would be the design's mistake: repeating the same request, reusing the key for another one,
 * and arriving while the first is still processing all call for different
 * diferentes.
 */
enum IdempotencyOutcome
{
    /** A new key: the request carries on and the result will be stored. */
    case Reserved;

    /** Same key, same request: the stored result is returned. */
    case Replayed;

    /** Same key, different request: this is the caller's bug. */
    case Conflict;

    /** Same key, the first request still in flight. */
    case InFlight;
}

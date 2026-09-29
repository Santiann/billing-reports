<?php

namespace App\Domain\Idempotency;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Lottery;

/**
 * Reserves idempotency keys and stores each one's result.
 *
 * The whole mechanism lives here; the middleware only translates it to HTTP. The
 * separation pays off at test time — and when the second idempotent operation
 * shows up, which will want the same mechanism and a different transport.
 */
final class IdempotencyStore
{
    /** The name comes from the IETF draft for the header (draft-ietf-httpapi-idempotency-key-header). */
    public const HEADER = 'Idempotency-Key';

    private const TABLE = 'idempotency_keys';

    /**
     * How long a key stays valid.
     *
     * Keeping them forever is not an option — the table would grow without bound, and
     * a key from months ago would replay a response that no longer describes the
     * record. Twenty-four hours comfortably covers what idempotency exists to cover:
     * the double click, the network retry, the resubmitted form.
     */
    private const TTL_HOURS = 24;

    /**
     * Tries to claim the key for this request.
     *
     * The INSERT comes first on purpose. Querying first and inserting afterwards would
     * leave a window between the two queries in which two simultaneous requests would
     * both get through — which is exactly the double-click case. Here the unique index
     * arbitrates, and whoever loses the race lands in the `catch` and works out what to
     * do from the row's state.
     */
    public function reserve(int $userId, string $key, string $fingerprint): IdempotencyReservation
    {
        $now = CarbonImmutable::now();

        try {
            DB::table(self::TABLE)->insert([
                'user_id' => $userId,
                'key' => $key,
                'fingerprint' => $fingerprint,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->clearExpired();

            return new IdempotencyReservation(IdempotencyOutcome::Reserved);
        } catch (UniqueConstraintViolationException) {
            // The key already belongs to some request. Which one, the row's state says.
        }

        $record = DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->where('key', $key)
            ->first();

        /*
         * The row existed at the INSERT and no longer does: it expired and was cleaned
         * up between the two queries. Nothing is reserved, so the request carries on —
         * `store()` recreates the row at the end.
         */
        if ($record === null) {
            return new IdempotencyReservation(IdempotencyOutcome::Reserved);
        }

        /*
         * An expired key is a new key.
         *
         * The row gets reused rather than deleted and reinserted: it is a single UPDATE,
         * and deleting would make room for a third request to insert in between. This
         * also applies to an in-flight row that expired — a request that died without
         * writing a response cannot lock the key up forever.
         */
        if (CarbonImmutable::parse($record->created_at)->addHours(self::TTL_HOURS)->isPast()) {
            DB::table(self::TABLE)->where('id', $record->id)->update([
                'fingerprint' => $fingerprint,
                'response_status' => null,
                'response_body' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return new IdempotencyReservation(IdempotencyOutcome::Reserved);
        }

        /*
         * Reserved and still without a response: the first request is in flight.
         *
         * This comes before the fingerprint comparison because there is nothing yet to
         * compare against nor to return. The honest answer is "try again shortly", and
         * the 409 is what stops both from processing.
         */
        if ($record->response_status === null) {
            return new IdempotencyReservation(IdempotencyOutcome::InFlight);
        }

        if (! hash_equals($record->fingerprint, $fingerprint)) {
            return new IdempotencyReservation(IdempotencyOutcome::Conflict);
        }

        return new IdempotencyReservation(
            IdempotencyOutcome::Replayed,
            (int) $record->response_status,
            (string) $record->response_body,
        );
    }

    /**
     * Stores the result so the next call with the same key receives it.
     *
     * `updateOrInsert` and not `update` because of the rare case above: if the reserved
     * row was cleaned up by expiry during processing, the result still needs to be kept.
     */
    public function store(int $userId, string $key, string $fingerprint, int $status, string $body): void
    {
        $now = CarbonImmutable::now();

        DB::table(self::TABLE)->updateOrInsert(
            ['user_id' => $userId, 'key' => $key],
            [
                'fingerprint' => $fingerprint,
                'response_status' => $status,
                'response_body' => $body,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    /**
     * Gives the key back to whoever used it.
     *
     * This serves the server-error case: a 500 is not a result, it is a failure. Storing
     * it would condemn the key to replaying the failure for 24 hours, when retrying the
     * request is precisely what the client should do.
     */
    public function release(int $userId, string $key): void
    {
        DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->where('key', $key)
            ->delete();
    }

    /**
     * Deletes expired keys, every now and then.
     *
     * By lottery, and not on every request, because cleaning up is maintenance and
     * cannot cost a DELETE on every write. It is the same strategy Laravel uses to
     * expire file-based sessions.
     *
     * It is not a scheduled task because this project runs no worker: scheduling it
     * would mean writing a cleanup that never runs.
     */
    private function clearExpired(): void
    {
        Lottery::odds(1, 200)->winner(function (): void {
            DB::table(self::TABLE)
                ->where('created_at', '<', CarbonImmutable::now()->subHours(self::TTL_HOURS))
                ->delete();
        })->choose();
    }
}

<?php

namespace App\Domain\Billing;

use Illuminate\Support\Facades\DB;

/**
 * A number that changes whenever the billings' data changes.
 *
 * The totals cache stores, alongside the totals, the version they were computed with, and
 * only serves them if that version is still the current one.
 *
 * `bump()` has to run INSIDE the write's transaction. There the row stays locked until the
 * commit, two simultaneous writes raise the number in a queue, and the new data and the new
 * version become visible at the same instant. Outside the transaction there would be an
 * interval between committing the data and committing the version in which the cache would
 * serve the earlier total.
 *
 * The price is precisely that queue: every write to a billing goes through this row. For
 * payments made by people it is imperceptible; the README records
 * a troca.
 */
final class BillingDataVersion
{
    private const TABLE = 'billing_data_versions';

    public function current(): int
    {
        return (int) DB::table(self::TABLE)->where('id', 1)->value('version');
    }

    public function bump(): void
    {
        DB::table(self::TABLE)->where('id', 1)->increment('version');
    }
}

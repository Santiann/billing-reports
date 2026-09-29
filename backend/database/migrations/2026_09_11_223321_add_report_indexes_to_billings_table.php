<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The report's indexes.
 *
 * There is a single principle: the EQUALITY column before the RANGE column. MySQL walks
 * a composite index left to right and stops using it at the first range column —
 * everything after it becomes a post-read filter, not a seek.
 *
 * Before these indexes, filtering by period meant a full scan:
 *
 *     EXPLAIN SELECT COUNT(*) FROM billings
 *     WHERE due_date >= '2026-01-01' AND due_date <= '2026-01-31'
 *     -> type: ALL   key: NULL   rows: 1989965
 *
 * That is why a one-month scope cost the same as a one-year one: the cost did not come
 * from the size of the scope, it came from scanning the table to find it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            // The user chooses which of the three dates defines the period, and MySQL
            // will not use a `due_date` index to filter `issue_date`. Each period basis
            // needs its own.
            //
            //   WHERE issue_date BETWEEN ? AND ?
            $table->index('issue_date', 'billings_issue_date_index');

            //   WHERE due_date BETWEEN ? AND ?
            $table->index('due_date', 'billings_due_date_index');

            //   WHERE payment_date BETWEEN ? AND ?
            // Null for an unpaid billing, which is convenient: filtering by payment date
            // already excludes the pending ones with no extra clause.
            $table->index('payment_date', 'billings_payment_date_index');

            // Filtering by customer combined with a period. `customer_id` is equality
            // and comes first; the date is a range and comes after.
            //
            // The foreign key already indexes `customer_id` on its own, but with that
            // MySQL finds the customer's rows and only then tests the date row by row.
            // With the pair, the date is a seek too.
            //
            //   WHERE customer_id = ? AND issue_date BETWEEN ? AND ?
            $table->index(['customer_id', 'issue_date'], 'billings_customer_issue_date_index');

            //   WHERE customer_id = ? AND due_date BETWEEN ? AND ?
            $table->index(['customer_id', 'due_date'], 'billings_customer_due_date_index');

            //   WHERE customer_id = ? AND payment_date BETWEEN ? AND ?
            $table->index(['customer_id', 'payment_date'], 'billings_customer_payment_date_index');

            // Status is equality and comes first. It serves two cases:
            //
            //   WHERE status = ? AND due_date BETWEEN ? AND ?
            //   WHERE status = 'pending' AND due_date < ?   (the "overdue" filter)
            //
            // Low selectivity on the first column — there are two values — but the range
            // on the second is what does the work, and the pair avoids scanning the paid
            // ones to find which pending ones went past due.
            $table->index(['status', 'due_date'], 'billings_status_due_date_index');
        });
    }

    /**
     * The order here is not cosmetic.
     *
     * The index the foreign key used was created automatically by InnoDB. When the
     * composites with `customer_id` on the left appeared, it discarded that one as
     * redundant — and started supporting the constraint with one of them.
     *
     * Dropping the composites directly fails with:
     *
     *     SQLSTATE[HY000] 1553 Cannot drop index
     *     'billings_customer_payment_date_index': needed in a foreign key
     *     constraint
     *
     * So the `customer_id` index is recreated FIRST, giving the constraint support of its
     * own back. Verified by running the rollback for real.
     */
    public function down(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->index('customer_id', 'billings_customer_id_foreign');
        });

        Schema::table('billings', function (Blueprint $table) {
            $table->dropIndex('billings_issue_date_index');
            $table->dropIndex('billings_due_date_index');
            $table->dropIndex('billings_payment_date_index');
            $table->dropIndex('billings_customer_issue_date_index');
            $table->dropIndex('billings_customer_due_date_index');
            $table->dropIndex('billings_customer_payment_date_index');
            $table->dropIndex('billings_status_due_date_index');
        });
    }
};

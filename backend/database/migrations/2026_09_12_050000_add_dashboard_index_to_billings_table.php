<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A COVERING index for the dashboard.
 *
 * The report's seven indexes point at the row; this one carries the values inside itself.
 * It is the difference between MySQL scanning the date range in the index and still
 * fetching each row from disk to sum it, or answering without touching the table at all —
 * EXPLAIN's `Using index` is the name for that.
 *
 * Measured against 2,000,000 billings, on the dashboard's two queries:
 *
 *                                        no covering    with this index
 *   12-month series (12 ranges)              8.25s           0.31s
 *   month indicators (with interest)         0.72s           0.07s
 *
 * The five columns are not excess, they are exactly what the two queries read. Removing
 * `monthly_interest_rate` alone already forces the interest calculation back to the table
 * row by row, and the indicators go from 0.07s to 0.72s.
 *
 * The order is not free either: `due_date` first because it is the range that narrows, and
 * the rest after, because they only need to be present to be read. An equality column
 * before a range applies when there is an equality — here there is none.
 *
 * The cost is known and accepted: 79 MB and one more tree to maintain on every insert. The
 * seeder's load already pays a 4.8x penalty because of the report's seven indexes, and
 * this is the eighth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            //   WHERE due_date >= ? AND due_date < ?
            //   -> COUNT, SUM(original_amount), SUM(paid_amount),
            //      and the interest calculation over the pending ones
            $table->index(
                [
                    'due_date',
                    'status',
                    'monthly_interest_rate',
                    'original_amount',
                    'paid_amount',
                ],
                'billings_dashboard_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->dropIndex('billings_dashboard_index');
        });
    }
};

<?php

namespace Database\Seeders;

/**
 * `billings`'s secondary indexes, with their columns in order.
 *
 * It exists so the volume seeder can drop them before the load and recreate them afterwards —
 * and recreate them CORRECTLY even when a previous run died halfway. Reading the definitions
 * from the database at that moment would not work: if the previous load was interrupted with
 * the indexes dropped, the database no longer knows what they were.
 *
 * It is a copy of the migrations, and the copy is watched: a test compares this list against
 * what the migrations actually create, and fails if the two drift apart.
 */
final class ReportIndexes
{
    /**
     * `name => columns in order`. The order matters: MySQL reads a composite index from left
     * to right.
     *
     * @var array<string, array<int, string>>
     */
    public const DEFINITIONS = [
        'billings_issue_date_index' => ['issue_date'],
        'billings_due_date_index' => ['due_date'],
        'billings_payment_date_index' => ['payment_date'],
        'billings_customer_issue_date_index' => ['customer_id', 'issue_date'],
        'billings_customer_due_date_index' => ['customer_id', 'due_date'],
        'billings_customer_payment_date_index' => ['customer_id', 'payment_date'],
        'billings_status_due_date_index' => ['status', 'due_date'],
        'billings_dashboard_index' => ['due_date', 'status', 'monthly_interest_rate', 'original_amount', 'paid_amount'],
    ];

    /**
     * A temporary index that holds the foreign key up during the load.
     *
     * `billings.customer_id` references `customers` and has no index of its own: MySQL leans on
     * the three indexes starting with `customer_id`. Dropping all three makes the `DROP INDEX`
     * be refused. The same name and the same device the index migration's `down()` uses.
     */
    public const FOREIGN_KEY_SUPPORT = 'billings_customer_id_foreign';
}

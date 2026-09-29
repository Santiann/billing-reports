<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billings', function (Blueprint $table) {
            $table->id();

            // restrictOnDelete: a billing is a financial record. Deleting a customer
            // must not evaporate their billing history.
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            $table->string('description');

            // DECIMAL, never FLOAT. Money in floating point accumulates cent-level
            // error, and the report sums millions of rows.
            $table->decimal('original_amount', 12, 2);

            // The monthly rate as a fraction: 0.0200 = 2% a month.
            $table->decimal('monthly_interest_rate', 6, 4)->default(0);

            // The three dates the user can choose as the period's basis.
            $table->date('issue_date');
            $table->date('due_date');
            $table->date('payment_date')->nullable();

            // "Overdue" is NOT a stored status: it is derivable from
            // status = 'pending' AND due_date < the reference date.
            // Storing it would require a daily job flipping rows from pending to
            // overdue, and between two runs the column would be lying. Deriving is
            // always correct and costs no writes.
            //
            // The reference date comes down from PHP, not from CURDATE(): MySQL's clock
            // does not move with travelTo(), and the consistency test would never close.
            // See InterestCalculator::overdueSql().
            $table->string('status', 20)->default('pending');

            // Freezing at the moment of payment.
            //
            // A paid billing accrues no interest: the displayed amount comes from here and
            // never from a recompute. Without these columns, a billing paid late would
            // change value with every day that passed.
            $table->decimal('paid_amount', 12, 2)->nullable();
            $table->decimal('paid_interest_amount', 12, 2)->nullable();

            $table->timestamps();

            // The report's composite indexes come in their own step
            // (feat: add report indexes), each alongside the query it serves. Only what
            // the schema's integrity already requires stays here.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billings');
    }
};

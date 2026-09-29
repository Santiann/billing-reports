<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The billings' audit trail.
 *
 * Insert only: a row is never updated nor deleted, and that is why there is no `updated_at`. A
 * wrong record is corrected with another record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_audits', function (Blueprint $table) {
            $table->id();

            /*
             * RESTRICT, the default, on both keys.
             *
             * Deleting a billing or a user that has history fails, rather than taking the
             * history along or leaving it pointing at nothing. The system deletes neither of
             * the two today; the key guarantees the trail is not the victim of the day it
             * does.
             *
             * The index the foreign key creates on `billing_id` already serves reading the
             * trail, `WHERE billing_id = ? ORDER BY id DESC`: in InnoDB a secondary index
             * carries the primary key at the end, so it is already in id order within each
             * billing.
             */
            $table->foreignId('billing_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained();

            $table->string('event', 20);

            // {field: {from, to}}, carrying only what changed.
            $table->json('changes');

            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_audits');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a request's result is stored so it can be replayed.
 *
 * The table is not a log: it exists to answer one question — "has this key been used, and
 * with what result?". That is why the row is deleted when it expires, and why the unique
 * index is the heart of the design.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();

            /*
             * The key belongs to whoever used it.
             *
             * Without the user in the unique key, two people drawing the same UUID — or a
             * client using "1" as a key — would see each other's response. Scoping per
             * user closes that without depending on the client choosing good keys.
             */
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key');

            /*
             * The request's fingerprint: method, path and payload.
             *
             * It is what tells "repeated the same request" from "reused the key for
             * something else". Without it, a buggy client would receive the result of an
             * operation it never asked for.
             */
            $table->char('fingerprint', 64);

            /*
             * Null while the request is in flight.
             *
             * The row is inserted BEFORE processing, precisely so a second simultaneous
             * request finds the key taken. The "reserved, no response" state is what makes
             * it possible to answer 409 instead of letting both process.
             */
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();

            $table->timestamps();

            /*
             * The unique index is not validation: it is the mechanism.
             *
             * Reserving the key is an INSERT, and the database is what arbitrates who wins
             * the race between two concurrent requests. Doing the check in PHP — SELECT
             * and then INSERT — would leave a window between the two in which both
             * requests would get through.
             */
            $table->unique(['user_id', 'key']);

            // So cleaning up the expired ones scans a range instead of the table.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};

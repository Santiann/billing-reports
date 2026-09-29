<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The invalidation clock for the totals cache.
 *
 * A single row, with a number that goes up on every write to `billings` — inside the write's
 * own transaction. The README explains why it is a stored row and not a number derived from the
 * data, such as `MAX(id)`: the derived one had a
 * corrida.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_data_versions', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('version');
        });

        DB::table('billing_data_versions')->insert(['id' => 1, 'version' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_data_versions');
    }
};

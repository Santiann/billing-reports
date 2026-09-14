<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O relógio de invalidação do cache dos totalizadores.
 *
 * Uma linha só, com um número que sobe a cada escrita em `billings` — dentro
 * da mesma transação da escrita. O README conta por que é uma linha gravada e
 * não um número derivado dos dados, como `MAX(id)`: o derivado tinha uma
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

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A trilha de auditoria das cobranças.
 *
 * Só inserção: a linha nunca é atualizada nem apagada, e por isso não existe
 * `updated_at`. Registro errado se corrige com outro registro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_audits', function (Blueprint $table) {
            $table->id();

            /*
             * RESTRICT, o default, nas duas chaves.
             *
             * Apagar uma cobrança ou um usuário que tem histórico falha, em vez
             * de levar o histórico junto ou deixá-lo apontando para o nada. O
             * sistema não apaga nenhum dos dois hoje; a chave garante que a
             * trilha não seja a vítima do dia em que apagar.
             *
             * O índice que a chave estrangeira cria em `billing_id` já serve a
             * leitura da trilha, `WHERE billing_id = ? ORDER BY id DESC`: no
             * InnoDB o índice secundário carrega a chave primária no fim, então
             * ele já está em ordem de id dentro de cada cobrança.
             */
            $table->foreignId('billing_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained();

            $table->string('event', 20);

            // {campo: {from, to}}, só com o que mudou.
            $table->json('changes');

            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_audits');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Onde o resultado de uma requisição fica guardado para poder ser repetido.
 *
 * A tabela não é um log: ela existe para responder uma pergunta só — "esta
 * chave já foi usada, e com que resultado?". Por isso a linha é apagada quando
 * vence, e por isso o índice único é o coração do desenho.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();

            /*
             * A chave pertence a quem a usou.
             *
             * Sem o usuário na chave única, duas pessoas que sorteassem o mesmo
             * UUID — ou um cliente que usasse "1" como chave — veriam a resposta
             * uma da outra. Escopo por usuário fecha isso sem depender de o
             * cliente escolher chaves boas.
             */
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key');

            /*
             * A impressão digital do pedido: método, caminho e payload.
             *
             * É o que distingue "repetiu a mesma requisição" de "reaproveitou a
             * chave para outra coisa". Sem ela, um cliente com bug receberia o
             * resultado de uma operação que não pediu.
             */
            $table->char('fingerprint', 64);

            /*
             * Nulos enquanto a requisição está em voo.
             *
             * A linha é inserida ANTES de processar, justamente para que uma
             * segunda requisição simultânea encontre a chave ocupada. O estado
             * "reservada, sem resposta" é o que permite responder 409 em vez de
             * deixar as duas processarem.
             */
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();

            $table->timestamps();

            /*
             * O índice único não é validação: é o mecanismo.
             *
             * A reserva da chave é um INSERT, e é o banco que arbitra quem
             * ganha a corrida entre duas requisições concorrentes. Fazer a
             * checagem em PHP — SELECT e depois INSERT — teria uma janela entre
             * as duas em que as duas requisições passariam.
             */
            $table->unique(['user_id', 'key']);

            // Para a limpeza das vencidas varrer por range em vez da tabela.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};

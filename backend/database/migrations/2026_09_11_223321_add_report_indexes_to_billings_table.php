<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices do relatório.
 *
 * O princípio é um só: coluna de IGUALDADE antes da coluna de RANGE. O MySQL
 * percorre um índice composto da esquerda para a direita e para de usá-lo na
 * primeira coluna de range — tudo que vier depois dela vira filtro pós-leitura,
 * não busca.
 *
 * Antes destes índices, filtrar por período fazia varredura completa:
 *
 *     EXPLAIN SELECT COUNT(*) FROM billings
 *     WHERE due_date >= '2026-01-01' AND due_date <= '2026-01-31'
 *     -> type: ALL   key: NULL   rows: 1989965
 *
 * Por isso um recorte de um mês custava o mesmo que um de um ano: o custo não
 * vinha do tamanho do recorte, vinha de varrer a tabela para encontrá-lo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            // O usuário escolhe qual das três datas define o período, e o
            // MySQL não usa um índice de `due_date` para filtrar `issue_date`.
            // Cada base de período precisa da sua.
            //
            //   WHERE issue_date BETWEEN ? AND ?
            $table->index('issue_date', 'billings_issue_date_index');

            //   WHERE due_date BETWEEN ? AND ?
            $table->index('due_date', 'billings_due_date_index');

            //   WHERE payment_date BETWEEN ? AND ?
            // Nulo para cobrança não paga, o que é conveniente: o filtro por
            // data de pagamento já exclui as pendentes sem cláusula extra.
            $table->index('payment_date', 'billings_payment_date_index');

            // Filtro por cliente combinado com período. `customer_id` é
            // igualdade e vem primeiro; a data é range e vem depois.
            //
            // A chave estrangeira já indexa `customer_id` sozinho, mas com ela
            // o MySQL encontra as linhas do cliente e só então testa a data
            // linha a linha. Com o par, a data também é busca.
            //
            //   WHERE customer_id = ? AND issue_date BETWEEN ? AND ?
            $table->index(['customer_id', 'issue_date'], 'billings_customer_issue_date_index');

            //   WHERE customer_id = ? AND due_date BETWEEN ? AND ?
            $table->index(['customer_id', 'due_date'], 'billings_customer_due_date_index');

            //   WHERE customer_id = ? AND payment_date BETWEEN ? AND ?
            $table->index(['customer_id', 'payment_date'], 'billings_customer_payment_date_index');

            // Status é igualdade e vem primeiro. Serve dois casos:
            //
            //   WHERE status = ? AND due_date BETWEEN ? AND ?
            //   WHERE status = 'pending' AND due_date < ?   (o filtro "vencida")
            //
            // Baixa seletividade na primeira coluna — são dois valores — mas o
            // range na segunda é que faz o trabalho, e o par evita varrer as
            // pagas para descobrir quais pendentes venceram.
            $table->index(['status', 'due_date'], 'billings_status_due_date_index');
        });
    }

    /**
     * A ordem aqui não é cosmética.
     *
     * O índice que a chave estrangeira usava era criado automaticamente pelo
     * InnoDB. Quando os compostos com `customer_id` à esquerda apareceram, ele
     * o descartou por redundância — e passou a apoiar a constraint num deles.
     *
     * Derrubar os compostos direto falha com:
     *
     *     SQLSTATE[HY000] 1553 Cannot drop index
     *     'billings_customer_payment_date_index': needed in a foreign key
     *     constraint
     *
     * Por isso o índice de `customer_id` é recriado ANTES, devolvendo à
     * constraint um apoio próprio. Verificado rodando o rollback de verdade.
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

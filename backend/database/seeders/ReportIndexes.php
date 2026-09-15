<?php

namespace Database\Seeders;

/**
 * Os índices secundários de `billings`, com as colunas na ordem.
 *
 * Existe para o seeder de volume conseguir derrubá-los antes da carga e
 * recriá-los depois — e recriá-los CERTO mesmo quando uma execução anterior
 * morreu no meio. Ler as definições do banco na hora não serviria: se a carga
 * anterior foi interrompida com os índices derrubados, o banco já não sabe
 * mais quais eram.
 *
 * É uma cópia das migrations, e a cópia é vigiada: um teste compara esta lista
 * com o que as migrations criam de fato, e falha se as duas divergirem.
 */
final class ReportIndexes
{
    /**
     * `nome => colunas na ordem`. A ordem importa: o MySQL lê o índice
     * composto da esquerda para a direita.
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
     * Índice provisório que sustenta a chave estrangeira durante a carga.
     *
     * `billings.customer_id` referencia `customers` e não tem índice próprio:
     * o MySQL se apoia nos três índices que começam por `customer_id`.
     * Derrubar os três faz o `DROP INDEX` ser recusado. O mesmo nome e o mesmo
     * recurso que o `down()` da migration de índices usa.
     */
    public const FOREIGN_KEY_SUPPORT = 'billings_customer_id_foreign';
}

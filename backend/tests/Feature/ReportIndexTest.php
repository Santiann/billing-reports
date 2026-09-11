<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Os índices do relatório são decisão de projeto, não detalhe de
 * infraestrutura: sem eles o filtro por período varre a tabela inteira.
 *
 * Este teste existe para que remover um índice quebre a suíte em vez de
 * degradar o relatório em silêncio — o tipo de regressão que só aparece em
 * produção, com volume, semanas depois.
 */
class ReportIndexTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Cada entrada é `nome => colunas na ordem`. A ordem importa: o MySQL lê
     * o índice composto da esquerda para a direita.
     *
     * @return array<string, array<int, string>>
     */
    private function expectedIndexes(): array
    {
        return [
            // Uma por base de período: o usuário escolhe qual data recorta.
            'billings_issue_date_index' => ['issue_date'],
            'billings_due_date_index' => ['due_date'],
            'billings_payment_date_index' => ['payment_date'],

            // Igualdade antes do range.
            'billings_customer_issue_date_index' => ['customer_id', 'issue_date'],
            'billings_customer_due_date_index' => ['customer_id', 'due_date'],
            'billings_customer_payment_date_index' => ['customer_id', 'payment_date'],

            // Serve o filtro por status com período e o "vencida" derivado.
            'billings_status_due_date_index' => ['status', 'due_date'],
        ];
    }

    public function test_indices_do_relatorio_existem_com_as_colunas_na_ordem_certa(): void
    {
        $actual = $this->indexesOfBillings();

        foreach ($this->expectedIndexes() as $name => $columns) {
            $this->assertArrayHasKey(
                $name,
                $actual,
                "O índice {$name} não existe. Sem ele o relatório volta a varrer a tabela.",
            );

            $this->assertSame(
                $columns,
                $actual[$name],
                "O índice {$name} está com as colunas fora de ordem. "
                .'Coluna de igualdade tem que vir antes da de range.',
            );
        }
    }

    /**
     * A asserção é sobre `possible_keys`, não sobre o plano escolhido.
     *
     * O otimizador escolhe varredura completa em tabela pequena porque ali ela
     * é mais barata, e a base de testes é pequena de propósito. Afirmar
     * `type != ALL` aqui falharia por motivo errado. `possible_keys` prova o
     * que importa neste nível: o índice SERVE a consulta.
     *
     * A prova de que o plano realmente muda está no README, medida contra os
     * dois milhões de linhas — type ALL com 1.989.965 linhas antes, range
     * depois.
     */
    public function test_filtro_por_periodo_tem_indice_aplicavel(): void
    {
        $plan = DB::select(
            'EXPLAIN SELECT COUNT(*) FROM billings WHERE due_date >= ? AND due_date <= ?',
            ['2026-01-01', '2026-01-31'],
        );

        $this->assertStringContainsString(
            'billings_due_date_index',
            (string) $plan[0]->possible_keys,
            'Nenhum índice de due_date é aplicável ao filtro por período.',
        );
    }

    public function test_filtro_por_cliente_com_periodo_tem_indice_composto_aplicavel(): void
    {
        $plan = DB::select(
            'EXPLAIN SELECT COUNT(*) FROM billings '
            .'WHERE customer_id = ? AND due_date >= ? AND due_date <= ?',
            [1, '2026-01-01', '2026-01-31'],
        );

        $this->assertStringContainsString(
            'billings_customer_due_date_index',
            (string) $plan[0]->possible_keys,
        );
    }

    public function test_filtro_de_vencidas_tem_indice_aplicavel(): void
    {
        $plan = DB::select(
            "EXPLAIN SELECT COUNT(*) FROM billings WHERE status = 'pending' AND due_date < ?",
            ['2026-06-15'],
        );

        $this->assertStringContainsString(
            'billings_status_due_date_index',
            (string) $plan[0]->possible_keys,
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function indexesOfBillings(): array
    {
        $rows = DB::select(
            'SELECT INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
            .'ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            ['billings'],
        );

        $indexes = [];

        foreach ($rows as $row) {
            $indexes[$row->INDEX_NAME][] = $row->COLUMN_NAME;
        }

        return $indexes;
    }
}

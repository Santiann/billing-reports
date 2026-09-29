<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The report's indexes are a design decision, not an infrastructure detail: without them the
 * period filter scans the whole table.
 *
 * This test exists so that removing an index breaks the suite instead of degrading the report in
 * silence — the kind of regression that only shows up in production, at volume, weeks later.
 */
class ReportIndexTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Each entry is `name => columns in order`. The order matters: MySQL reads a composite index
     * from left to right.
     *
     * @return array<string, array<int, string>>
     */
    private function expectedIndexes(): array
    {
        return [
            // One per period basis: the user chooses which date narrows.
            'billings_issue_date_index' => ['issue_date'],
            'billings_due_date_index' => ['due_date'],
            'billings_payment_date_index' => ['payment_date'],

            // Equality before the range.
            'billings_customer_issue_date_index' => ['customer_id', 'issue_date'],
            'billings_customer_due_date_index' => ['customer_id', 'due_date'],
            'billings_customer_payment_date_index' => ['customer_id', 'payment_date'],

            // Serves the status-plus-period filter and the derived "overdue".
            'billings_status_due_date_index' => ['status', 'due_date'],
        ];
    }

    public function test_the_report_indexes_exist_with_the_columns_in_the_right_order(): void
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
     * The assertion is about `possible_keys`, not about the chosen plan.
     *
     * The optimiser picks a full scan on a small table because there it is cheaper, and the test
     * base is small on purpose. Asserting `type != ALL` here would fail for the wrong reason.
     * `possible_keys` proves what matters at this level: the index SERVES the query.
     *
     * The proof that the plan really changes is in the README, measured against the two million
     * rows — type ALL with 1,989,965 rows before, range after.
     */
    public function test_the_period_filter_has_an_applicable_index(): void
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

    public function test_the_customer_plus_period_filter_has_an_applicable_composite_index(): void
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

    public function test_the_overdue_filter_has_an_applicable_index(): void
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

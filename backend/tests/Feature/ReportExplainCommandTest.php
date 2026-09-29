<?php

namespace Tests\Feature;

use App\Domain\Report\BillingReportFilters;
use App\Domain\Report\BillingReportQuery;
use App\Models\Billing;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `report:explain` — the report's execution plan as a tool.
 *
 * The README's index measurements were made by hand, pasting queries into the MySQL client. The
 * problem is not the effort: it is that the hand-pasted query may no longer be the one the code
 * runs. This command takes the queries from the SAME path the API uses — pagination included —
 * and explains each one.
 *
 * What the tests assert is the command's contract: which queries it finds, that it is not fooled
 * by the totals cache, and that it refuses an invalid option instead of silently falling back to
 * the default.
 */
class ReportExplainCommandTest extends TestCase
{
    use RefreshDatabase;

    private function billings(int $quantidade = 3): void
    {
        Billing::factory()->count($quantidade)->create();
    }

    // --- what it finds ------------------------------------------------

    public function test_explains_the_report_queries(): void
    {
        $this->billings();

        $this->artisan('report:explain')
            ->assertExitCode(0)
            ->expectsOutputToContain('Contagem da paginação')
            ->expectsOutputToContain('Página do relatório')
            ->expectsOutputToContain('Totalizadores');
    }

    /** The SQL gets printed: it is what lets you check whether it is what you thought. */
    public function test_prints_the_sql_and_the_plan_of_each_query(): void
    {
        $this->billings();

        $this->artisan('report:explain')
            ->assertExitCode(0)
            // The totals' aggregation, recognisable by its alias.
            ->expectsOutputToContain('total_count')
            // MySQL EXPLAIN columns.
            ->expectsOutputToContain('possible_keys');
    }

    public function test_the_applied_scope_appears_in_the_header(): void
    {
        $cliente = Customer::factory()->create();
        Billing::factory()->create(['customer_id' => $cliente->id]);

        $this->artisan('report:explain', [
            '--date-field' => 'issue_date',
            '--start' => '2026-01-01',
            '--end' => '2026-12-31',
            '--customer' => (string) $cliente->id,
            '--status' => 'pending',
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('issue_date')
            ->expectsOutputToContain('2026-01-01')
            ->expectsOutputToContain((string) $cliente->id);
    }

    /**
     * The command explains the QUERY, so it cannot be served from the totals cache — otherwise
     * the aggregation would vanish from the very tool built to look at it.
     */
    public function test_the_totals_cache_does_not_hide_the_aggregation(): void
    {
        $this->billings();

        // Warms the cache through the same path as the API.
        app(BillingReportQuery::class)->totals(new BillingReportFilters());

        $this->artisan('report:explain')
            ->assertExitCode(0)
            ->expectsOutputToContain('total_count');
    }

    // --- EXPLAIN ANALYZE ----------------------------------------------

    /** With `--analyze`, MySQL executes and returns the actual time per operation. */
    public function test_analyze_brings_the_actual_time_of_each_operation(): void
    {
        $this->billings();

        $this->artisan('report:explain', ['--analyze' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('actual time');
    }

    /**
     * With `--literals`, the same SQL is explained with the values inlined.
     *
     * It serves a concrete question the cache measurement left open: the application sends the
     * dates as bound parameters, and the hand-made measurement sent them as literals. If the
     * plan changes, this is where it shows.
     */
    public function test_literals_also_explains_with_the_values_inlined(): void
    {
        $this->billings();

        $this->artisan('report:explain', [
            '--start' => '2026-01-01',
            '--end' => '2026-12-31',
            '--literals' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('valores embutidos')
            ->expectsOutputToContain("'2026-01-01'");
    }

    // --- recusas ------------------------------------------------------

    /**
     * An invalid option fails loudly.
     *
     * The filters object discards a value outside the allowlist and falls back to the default —
     * the right protection for the API, because the value becomes a column name in SQL. In a
     * diagnostic command, silently falling back to the default would have someone measure the
     * wrong scope and never find out.
     */
    public function test_an_invalid_date_basis_is_refused(): void
    {
        $this->artisan('report:explain', ['--date-field' => 'data_qualquer'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Base da data inválida');
    }

    public function test_an_invalid_sort_is_refused(): void
    {
        $this->artisan('report:explain', ['--sort' => 'coluna_inexistente'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Ordenação inválida');
    }

    public function test_an_invalid_status_is_refused(): void
    {
        $this->artisan('report:explain', ['--status' => 'quitada'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Status inválido');
    }

    public function test_an_invalid_date_is_refused(): void
    {
        $this->artisan('report:explain', ['--start' => '31/02/2026'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Data inválida');
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Report\BillingReportFilters;
use App\Domain\Report\BillingReportQuery;
use App\Models\Billing;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `report:explain` — o plano de execução do relatório como ferramenta.
 *
 * As medições de índice do README foram feitas na mão, colando consultas no
 * cliente do MySQL. O problema não é o trabalho: é que a consulta colada à mão
 * pode não ser mais a que o código roda. Este comando pega as consultas do
 * MESMO caminho que a API usa — inclusive a paginação — e explica cada uma.
 *
 * O que os testes afirmam é o contrato do comando: quais consultas ele
 * encontra, que ele não é enganado pelo cache dos totalizadores, e que recusa
 * opção inválida em vez de silenciosamente cair no default.
 */
class ReportExplainCommandTest extends TestCase
{
    use RefreshDatabase;

    private function cobrancas(int $quantidade = 3): void
    {
        Billing::factory()->count($quantidade)->create();
    }

    // --- o que ele encontra -------------------------------------------

    public function test_explains_the_report_queries(): void
    {
        $this->cobrancas();

        $this->artisan('report:explain')
            ->assertExitCode(0)
            ->expectsOutputToContain('Contagem da paginação')
            ->expectsOutputToContain('Página do relatório')
            ->expectsOutputToContain('Totalizadores');
    }

    /** O SQL vai impresso: é o que permite conferir se é o que se pensava. */
    public function test_prints_the_sql_and_the_plan_of_each_query(): void
    {
        $this->cobrancas();

        $this->artisan('report:explain')
            ->assertExitCode(0)
            // A agregação dos totalizadores, reconhecível pelo alias.
            ->expectsOutputToContain('total_count')
            // Colunas do EXPLAIN do MySQL.
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
     * O comando explica a CONSULTA, então não pode ser servido pelo cache dos
     * totalizadores — senão a agregação desapareceria justamente da ferramenta
     * feita para olhá-la.
     */
    public function test_the_totals_cache_does_not_hide_the_aggregation(): void
    {
        $this->cobrancas();

        // Aquece o cache pelo mesmo caminho da API.
        app(BillingReportQuery::class)->totals(new BillingReportFilters());

        $this->artisan('report:explain')
            ->assertExitCode(0)
            ->expectsOutputToContain('total_count');
    }

    // --- EXPLAIN ANALYZE ----------------------------------------------

    /** Com `--analyze`, o MySQL executa e devolve o tempo real por operação. */
    public function test_analyze_brings_the_actual_time_of_each_operation(): void
    {
        $this->cobrancas();

        $this->artisan('report:explain', ['--analyze' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('actual time');
    }

    /**
     * Com `--literals`, o mesmo SQL é explicado com os valores embutidos.
     *
     * Serve a uma pergunta concreta que a medição do cache deixou aberta: a
     * aplicação manda as datas como parâmetro vinculado, e a medição à mão as
     * mandou literais. Se o plano mudar, é aqui que aparece.
     */
    public function test_literals_also_explains_with_the_values_inlined(): void
    {
        $this->cobrancas();

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
     * Opção inválida falha em voz alta.
     *
     * O objeto de filtros descarta valor fora da allowlist e cai no default —
     * proteção certa para a API, porque o valor vira nome de coluna em SQL. Num
     * comando de diagnóstico, cair no default em silêncio faria alguém medir o
     * recorte errado e não descobrir.
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

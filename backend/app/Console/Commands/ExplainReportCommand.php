<?php

namespace App\Console\Commands;

use App\Domain\Report\BillingReportFilters;
use App\Domain\Report\BillingReportQuery;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `report:explain` — o plano de execução do relatório, versionado.
 *
 * As medições de índice do README foram feitas colando consultas no cliente do
 * MySQL. O trabalho não é o problema: o problema é que a consulta colada à mão
 * envelhece sem avisar, e passa a descrever um SQL que o código não gera mais.
 *
 * Este comando não tem SQL escrito dentro dele. Ele roda o MESMO caminho que a
 * API usa — inclusive o `paginate()`, que emite uma consulta de contagem que
 * ninguém escreveu à mão —, escuta o que o Eloquent mandou para o banco, e
 * explica cada consulta capturada. Se o relatório mudar, o comando muda junto.
 */
final class ExplainReportCommand extends Command
{
    protected $signature = 'report:explain
        {--date-field=due_date : Base do período: issue_date, due_date ou payment_date}
        {--start= : Início do período, AAAA-MM-DD}
        {--end= : Fim do período, AAAA-MM-DD}
        {--customer= : Id do cliente}
        {--status= : pending, paid ou overdue}
        {--sort=due_date : Coluna de ordenação}
        {--direction=desc : asc ou desc}
        {--per-page=25 : Linhas por página}
        {--analyze : Executa as consultas com EXPLAIN ANALYZE e mostra o tempo real de cada operação}
        {--literals : Explica também o SQL com os valores embutidos, em vez de parâmetros vinculados}';

    protected $description = 'Roda EXPLAIN nas consultas do relatório de faturamento e imprime o plano';

    /** Colunas do EXPLAIN que dizem algo; as outras só alargam a tabela. */
    private const COLUMNS = [
        'select_type', 'table', 'type', 'possible_keys', 'key', 'rows', 'filtered', 'Extra',
    ];

    /** @var array<int, array{sql: string, bindings: array<int, mixed>, time: float}> */
    private array $captured = [];

    public function handle(BillingReportQuery $report): int
    {
        $filters = $this->filters();

        if ($filters === null) {
            return self::FAILURE;
        }

        $this->header($filters);

        DB::listen(function ($query): void {
            $this->captured[] = [
                'sql' => $query->sql,
                'bindings' => $query->bindings,
                'time' => $query->time,
            ];
        });

        /*
         * O mesmo caminho da API, com uma diferença deliberada: os totais saem
         * de `computeTotals()`, sem passar pelo cache. O cache é justamente o
         * que esta ferramenta não pode enxergar — senão a consulta mais cara do
         * relatório desapareceria da ferramenta feita para olhá-la.
         */
        $report->rows($filters)->paginate($this->pageSize());
        $report->computeTotals($filters);

        foreach ($this->captured as $query) {
            $this->explain($query);
        }

        $this->newLine();

        return self::SUCCESS;
    }

    private function header(BillingReportFilters $filters): void
    {
        $this->newLine();
        $this->line('  <options=bold>Relatório de faturamento — plano de execução</>');
        $this->newLine();
        $this->line('  Base da data   '.$filters->dateField);
        $this->line('  Período        '.($filters->startDate ?? 'sem início').' a '.($filters->endDate ?? 'sem fim'));
        $this->line('  Cliente        '.($filters->customerId ?? 'todos'));
        $this->line('  Status         '.($filters->status ?? 'todos'));
        $this->line('  Ordenação      '.$filters->sort.' '.$filters->direction);
        $this->line('  Por página     '.$this->pageSize());
    }

    /**
     * @param  array{sql: string, bindings: array<int, mixed>, time: float}  $query
     */
    private function explain(array $query): void
    {
        $this->newLine();
        $this->line('  <fg=yellow>── '.$this->label($query['sql']).'</>');
        $this->newLine();
        $this->line('  '.$query['sql']);
        $this->line(sprintf('  <fg=gray>executada em %.1f ms</>', $query['time']));

        // Explicar um SELECT na tabela de cache ou de versão seria ruído: o
        // assunto é o plano sobre as cobranças.
        if (! str_contains($query['sql'], '`billings`')) {
            return;
        }

        $this->plan($query['sql'], $query['bindings']);

        if (! $this->option('literals')) {
            return;
        }

        /*
         * O mesmo SQL com os valores embutidos.
         *
         * Existe por uma dúvida concreta: a aplicação manda as datas como
         * parâmetro vinculado, e a medição feita à mão as mandou literais. O
         * otimizador do MySQL enxerga o valor no segundo caso e pode escolher
         * outro plano. Se escolher, a diferença aparece aqui lado a lado.
         */
        $literal = $this->withLiterals($query['sql'], $query['bindings']);

        $this->newLine();
        $this->line('  <fg=yellow>   o mesmo SQL, com os valores embutidos</>');
        $this->newLine();
        $this->line('  '.$literal);
        $this->plan($literal, []);
    }

    /** @param  array<int, mixed>  $bindings */
    private function plan(string $sql, array $bindings): void
    {
        if ($this->option('analyze')) {
            $tree = (array) DB::selectOne('EXPLAIN ANALYZE '.$sql, $bindings);

            $this->newLine();
            $this->line('  '.str_replace("\n", "\n  ", trim((string) reset($tree))));

            return;
        }

        $rows = array_map(
            fn (object $row): array => array_map(
                fn (string $column): string => $this->shorten(((array) $row)[$column] ?? null),
                array_combine(self::COLUMNS, self::COLUMNS),
            ),
            DB::select('EXPLAIN '.$sql, $bindings),
        );

        $this->table(self::COLUMNS, $rows);
    }

    private function shorten(mixed $value): string
    {
        $text = $value === null ? '—' : (string) $value;

        // `possible_keys` lista todos os índices candidatos e estoura a
        // largura do terminal sem acrescentar informação.
        return mb_strlen($text) > 40 ? mb_substr($text, 0, 39).'…' : $text;
    }

    private function label(string $sql): string
    {
        return match (true) {
            str_contains($sql, 'count(*) as `aggregate`') => 'Contagem da paginação',
            str_contains($sql, 'total_count') => 'Totalizadores',
            str_contains($sql, 'from `customers`') => 'Clientes da página',
            str_contains($sql, '`billings`') => 'Página do relatório',
            default => 'Outra consulta',
        };
    }

    /**
     * Troca cada `?` pelo valor, escapado pelo próprio driver.
     *
     * @param  array<int, mixed>  $bindings
     */
    private function withLiterals(string $sql, array $bindings): string
    {
        foreach ($bindings as $value) {
            $literal = match (true) {
                $value === null => 'NULL',
                is_bool($value) => $value ? '1' : '0',
                is_int($value), is_float($value) => (string) $value,
                default => DB::getPdo()->quote((string) $value),
            };

            // Callback, e não string de substituição: um valor com `$` seria
            // interpretado como referência de grupo.
            $sql = (string) preg_replace_callback('/\?/', fn (): string => $literal, $sql, 1);
        }

        return $sql;
    }

    private function pageSize(): int
    {
        return max(1, (int) $this->option('per-page'));
    }

    /**
     * Os filtros, ou nulo quando alguma opção não vale.
     *
     * A recusa é em voz alta de propósito. `BillingReportFilters` descarta
     * valor fora da allowlist e cai no default — proteção certa para a API,
     * porque esses valores viram nome de coluna em SQL. Num diagnóstico, cair
     * no default em silêncio faria alguém medir um recorte que não é o que
     * pediu, e concluir a coisa errada.
     */
    private function filters(): ?BillingReportFilters
    {
        $dateField = (string) $this->option('date-field');
        $sort = (string) $this->option('sort');
        $direction = (string) $this->option('direction');
        $status = (string) $this->option('status');
        $customer = (string) $this->option('customer');

        if (! in_array($dateField, BillingReportFilters::DATE_FIELDS, true)) {
            $this->error('Base da data inválida. Use: '.implode(', ', BillingReportFilters::DATE_FIELDS).'.');

            return null;
        }

        if (! in_array($sort, BillingReportFilters::SORTABLE, true)) {
            $this->error('Ordenação inválida. Permitido: '.implode(', ', BillingReportFilters::SORTABLE).'.');

            return null;
        }

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $this->error('Direção inválida. Use asc ou desc.');

            return null;
        }

        if ($status !== '' && ! in_array($status, BillingReportFilters::STATUSES, true)) {
            $this->error('Status inválido. Use: '.implode(', ', BillingReportFilters::STATUSES).'.');

            return null;
        }

        if ($customer !== '' && ! ctype_digit($customer)) {
            $this->error('Cliente inválido. Informe o id, só dígitos.');

            return null;
        }

        foreach (['start', 'end'] as $option) {
            $data = (string) $this->option($option);

            if ($data !== '' && ! $this->validDate($data)) {
                $this->error("Data inválida em --{$option}: {$data}. Use AAAA-MM-DD.");

                return null;
            }
        }

        return BillingReportFilters::fromArray([
            'date_field' => $dateField,
            'start_date' => ($this->option('start') ?: null),
            'end_date' => ($this->option('end') ?: null),
            'customer_id' => $customer !== '' ? (int) $customer : null,
            'status' => $status !== '' ? $status : null,
            'sort' => $sort,
            'direction' => $direction,
        ]);
    }

    /**
     * `DateTimeImmutable` e não Carbon: o Carbon lança exceção quando o valor
     * não casa com o formato, e aqui a data errada é entrada esperada, não
     * acidente. É a mesma escolha da importação de CSV.
     *
     * A volta com `format` existe porque 31/02 rola para março em vez de
     * falhar.
     */
    private function validDate(string $value): bool
    {
        $data = DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $data !== false && $data->format('Y-m-d') === $value;
    }
}

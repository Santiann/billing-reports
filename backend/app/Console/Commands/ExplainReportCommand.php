<?php

namespace App\Console\Commands;

use App\Domain\Report\BillingReportFilters;
use App\Domain\Report\BillingReportQuery;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `report:explain` — the report's execution plan, under version control.
 *
 * The README's index measurements were made by pasting queries into the MySQL client.
 * The effort is not the problem: the problem is that a hand-pasted query ages without
 * warning, and goes on to describe SQL the code no longer generates.
 *
 * This command has no SQL written inside it. It runs the SAME path the API uses —
 * including `paginate()`, which emits a count query nobody wrote by hand — listens to
 * what Eloquent sent to the database, and explains every captured query. If the report
 * changes, the command changes with it.
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

    /** The EXPLAIN columns that say something; the others only widen the table. */
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
         * The same path as the API, with one deliberate difference: the totals come from
         * `computeTotals()`, bypassing the cache. The cache is precisely what this tool
         * must not see — otherwise the report's most expensive query would vanish from
         * the tool built to look at it.
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

        // Explaining a SELECT on the cache or version table would be noise: the subject
        // is the plan over the billings.
        if (! str_contains($query['sql'], '`billings`')) {
            return;
        }

        $this->plan($query['sql'], $query['bindings']);

        if (! $this->option('literals')) {
            return;
        }

        /*
         * The same SQL with the values inlined.
         *
         * It exists because of a concrete doubt: the application sends the dates as bound
         * parameters, and the hand-made measurement sent them as literals. MySQL's
         * optimiser can see the value in the second case and may choose another plan. If
         * it does, the difference shows up here side by side.
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

        // `possible_keys` lists every candidate index and blows past the terminal's
        // width without adding information.
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
     * Replaces each `?` with its value, escaped by the driver itself.
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

            // A callback, not a replacement string: a value containing `$` would be read
            // as a group reference.
            $sql = (string) preg_replace_callback('/\?/', fn (): string => $literal, $sql, 1);
        }

        return $sql;
    }

    private function pageSize(): int
    {
        return max(1, (int) $this->option('per-page'));
    }

    /**
     * The filters, or null when some option is not valid.
     *
     * The refusal is loud on purpose. `BillingReportFilters` discards a value outside the
     * allowlist and falls back to the default — the right protection for the API, because
     * those values become column names in SQL. In a diagnostic, silently falling back to
     * the default would have someone measure a scope that is not the one they asked for,
     * and conclude the wrong thing.
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
     * `DateTimeImmutable` and not Carbon: Carbon throws when the value does not match the
     * format, and here a wrong date is expected input, not an accident. It is the same
     * choice as in the CSV import.
     *
     * The round trip through `format` exists because 31/02 rolls into March instead of
     * failing.
     */
    private function validDate(string $value): bool
    {
        $data = DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $data !== false && $data->format('Y-m-d') === $value;
    }
}

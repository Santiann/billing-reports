<?php

namespace App\Domain\Report;

use App\Domain\Billing\BillingDataVersion;
use App\Domain\Billing\BillingStatus;
use App\Domain\Billing\InterestCalculator;
use App\Models\Billing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Monta a consulta do relatório e a dos totalizadores.
 *
 * As duas partem dos MESMOS filtros. É o que garante que o rodapé do relatório
 * fale do mesmo conjunto que as linhas exibidas.
 */
final class BillingReportQuery
{
    private const CACHE_PREFIX = 'report-totals:';

    private readonly InterestCalculator $calculator;

    public function __construct(
        ?InterestCalculator $calculator = null,
        private readonly BillingDataVersion $version = new BillingDataVersion(),
    ) {
        $this->calculator = $calculator ?? new InterestCalculator();
    }

    /**
     * Linhas do relatório, já paginadas pelo chamador.
     *
     * @return Builder<Billing>
     */
    public function rows(BillingReportFilters $filters): Builder
    {
        $query = Billing::query()->with('customer');

        $this->applyFilters($query, $filters);

        $query
            ->select('billings.*')
            ->selectRaw("{$this->calculator->updatedAmountSql()} as updated_amount")
            ->selectRaw("{$this->calculator->interestAmountSql()} as interest_amount");

        // Ordenar por `updated_amount` só é possível porque o valor existe em
        // SQL. Com o cálculo apenas em PHP, ordenar por ele obrigaria a
        // carregar o conjunto inteiro em memória.
        $query->orderBy($filters->sort, $filters->direction);

        // Desempate estável: sem isso, duas páginas podem repetir ou pular
        // linhas quando há empate na coluna ordenada.
        $query->orderBy('billings.id', 'asc');

        return $query;
    }

    /**
     * Totalizadores, do cache quando ainda valem.
     *
     * Uma entrada por recorte, e não uma por versão: o valor guarda a versão
     * dos dados e a data de referência com que foi calculado, e é sobrescrito
     * quando uma das duas muda. Assim a tabela de cache cresce com o número de
     * recortes consultados, e não com o número de escritas — o driver de banco
     * só apaga entrada vencida quando alguém a lê, e chave abandonada ficaria
     * lá para sempre.
     *
     * A ordem das duas leituras é a garantia. A versão é lida ANTES de
     * calcular, então os totais gravados foram calculados sobre dados no mínimo
     * tão novos quanto a versão que os acompanha. Se uma escrita entrar no meio,
     * a versão corrente sobe e a entrada simplesmente não é servida. O inverso
     * — dado velho sob versão nova — não tem como acontecer.
     *
     * @return array<string, mixed>
     */
    public function totals(BillingReportFilters $filters): array
    {
        $versao = $this->version->current();
        $data = $this->calculator->referenceDate()->toDateString();
        $chave = self::CACHE_PREFIX.hash('sha256', (string) json_encode($filters->scope()));

        $guardado = Cache::get($chave);

        if (is_array($guardado) && $guardado['version'] === $versao && $guardado['date'] === $data) {
            return $guardado['totals'];
        }

        $totais = $this->computeTotals($filters);

        Cache::put($chave, ['version' => $versao, 'date' => $data, 'totals' => $totais], now()->addDay());

        return $totais;
    }

    /**
     * Totalizadores sobre o conjunto filtrado INTEIRO.
     *
     * Consulta de agregação separada, nunca a soma da página corrente: o
     * usuário na página 3 precisa ver o total do relatório, não o da página.
     *
     * @return array<string, mixed>
     */
    private function computeTotals(BillingReportFilters $filters): array
    {
        $query = Billing::query();

        $this->applyFilters($query, $filters);

        $updated = $this->calculator->updatedAmountSql();
        $interest = $this->calculator->interestAmountSql();
        $paid = BillingStatus::Paid->value;

        // Recebido sai das colunas congeladas; pendente, do valor atualizado.
        $received = "CASE WHEN billings.status = '{$paid}' THEN COALESCE(billings.paid_amount, 0) ELSE 0 END";
        $pending = "CASE WHEN billings.status = '{$paid}' THEN 0 ELSE {$updated} END";

        $row = $query->selectRaw(
            'COUNT(*) as total_count,'
            .' COALESCE(SUM(billings.original_amount), 0) as original_amount,'
            ." COALESCE(SUM({$interest}), 0) as interest_amount,"
            ." COALESCE(SUM({$updated}), 0) as updated_amount,"
            ." COALESCE(SUM({$received}), 0) as paid_amount,"
            ." COALESCE(SUM({$pending}), 0) as pending_amount",
        )->first();

        return [
            'count' => (int) ($row->total_count ?? 0),
            'original_amount' => $this->money($row->original_amount ?? 0),
            'interest_amount' => $this->money($row->interest_amount ?? 0),
            'updated_amount' => $this->money($row->updated_amount ?? 0),
            'paid_amount' => $this->money($row->paid_amount ?? 0),
            'pending_amount' => $this->money($row->pending_amount ?? 0),
        ];
    }

    /**
     * @param  Builder<Billing>  $query
     */
    private function applyFilters(Builder $query, BillingReportFilters $filters): void
    {
        // O nome da coluna vem de allowlist, nunca cru da requisição.
        $dateColumn = "billings.{$filters->dateField}";

        // Comparação direta, não whereDate(): envolver a coluna em DATE()
        // impede o MySQL de usar o índice, e o relatório é justamente onde
        // isso não pode acontecer. As colunas já são do tipo DATE.
        if ($filters->startDate !== null) {
            $query->where($dateColumn, '>=', $filters->startDate);
        }

        if ($filters->endDate !== null) {
            $query->where($dateColumn, '<=', $filters->endDate);
        }

        if ($filters->customerId !== null) {
            $query->where('billings.customer_id', $filters->customerId);
        }

        match ($filters->status) {
            'paid' => $query->where('billings.status', BillingStatus::Paid->value),
            'pending' => $query->where('billings.status', BillingStatus::Pending->value),
            // Derivada, e vinda da mesma fonte da regra de juros.
            'overdue' => $query->whereRaw($this->calculator->overdueSql()),
            default => null,
        };
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}

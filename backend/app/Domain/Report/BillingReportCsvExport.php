<?php

namespace App\Domain\Report;

use App\Models\Billing;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportação do relatório em CSV.
 *
 * Escreve linha a linha em `php://output` enquanto percorre o resultado com
 * `lazy()`. Em nenhum momento o conjunto existe inteiro em memória — é o que
 * permite exportar um recorte de centenas de milhares de cobranças sem
 * estourar o processo.
 *
 * Usa o MESMO BillingReportQuery e o MESMO objeto de filtros que a tela. É o
 * que garante que o arquivo exportado seja o relatório que o usuário está
 * vendo, e não uma segunda consulta parecida.
 */
final class BillingReportCsvExport
{
    /** Linhas por bloco lido do banco. */
    private const CHUNK = 1_000;

    /** A cada N linhas o buffer é esvaziado, para o download começar já. */
    private const FLUSH_EVERY = 500;

    private const DELIMITER = ';';

    public function __construct(private readonly BillingReportQuery $report) {}

    public function stream(BillingReportFilters $filters): StreamedResponse
    {
        $filename = 'relatorio-faturamento-'
            .CarbonImmutable::now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(
            fn () => $this->write($filters),
            $filename,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                // Sem isto, proxies e o próprio browser podem tentar bufferizar.
                'X-Accel-Buffering' => 'no',
                'Cache-Control' => 'no-store',
            ],
        );
    }

    private function write(BillingReportFilters $filters): void
    {
        $out = fopen('php://output', 'wb');

        // BOM: sem ele o Excel abre UTF-8 como Latin-1 e os acentos viram
        // lixo. É o formato que quem recebe este arquivo vai usar.
        fwrite($out, "\xEF\xBB\xBF");

        $this->writeContext($out, $filters);
        $this->writeColumns($out);

        $written = 0;

        foreach ($this->report->rows($filters)->lazy(self::CHUNK) as $billing) {
            $this->writeRow($out, $billing);

            if (++$written % self::FLUSH_EVERY === 0) {
                flush();
            }
        }

        $this->writeTotals($out, $filters);

        fclose($out);
    }

    /**
     * Período e filtros aplicados, no topo do arquivo.
     *
     * @param  resource  $out
     */
    private function writeContext($out, BillingReportFilters $filters): void
    {
        $this->put($out, ['Relatório de faturamento']);
        $this->put($out, ['Gerado em', CarbonImmutable::now()->format('d/m/Y H:i')]);
        $this->put($out, []);

        $this->put($out, ['Período baseado em', $filters->dateFieldLabel()]);
        $this->put($out, [
            'Período',
            $this->date($filters->startDate).' a '.$this->date($filters->endDate),
        ]);
        $this->put($out, ['Status', $filters->statusLabel()]);
        $this->put($out, ['Cliente', $this->customerName($filters)]);
        $this->put($out, []);
    }

    /** @param  resource  $out */
    private function writeColumns($out): void
    {
        $this->put($out, [
            'Cliente', 'Descrição', 'Emissão', 'Vencimento', 'Status',
            'Valor original', 'Juros', 'Valor atualizado', 'Valor pago',
        ]);
    }

    /** @param  resource  $out */
    private function writeRow($out, Billing $billing): void
    {
        $this->put($out, [
            $billing->customer?->name ?? '',
            $billing->description,
            $billing->issue_date->format('d/m/Y'),
            $billing->due_date->format('d/m/Y'),
            $billing->isOverdue() ? 'Vencida' : $billing->status->label(),
            $this->money($billing->getAttribute('original_amount')),
            $this->money($billing->getAttribute('interest_amount')),
            $this->money($billing->getAttribute('updated_amount')),
            $billing->paid_amount === null ? '' : $this->money($billing->paid_amount),
        ]);
    }

    /**
     * Totalizadores no rodapé, da consulta de agregação sobre o conjunto
     * filtrado inteiro — não a soma das linhas que acabaram de ser escritas.
     *
     * @param  resource  $out
     */
    private function writeTotals($out, BillingReportFilters $filters): void
    {
        $totals = $this->report->totals($filters);

        $this->put($out, []);
        $this->put($out, [
            'TOTAIS', 'Quantidade', 'Valor original', 'Total de juros',
            'Valor atualizado', 'Recebido', 'Pendente',
        ]);
        $this->put($out, [
            '',
            (string) $totals['count'],
            $this->money($totals['original_amount']),
            $this->money($totals['interest_amount']),
            $this->money($totals['updated_amount']),
            $this->money($totals['paid_amount']),
            $this->money($totals['pending_amount']),
        ]);
    }

    /**
     * @param  resource  $out
     * @param  array<int, string>  $fields
     */
    private function put($out, array $fields): void
    {
        fputcsv($out, $fields, self::DELIMITER, '"', '\\');
    }

    /**
     * Formato brasileiro: quem abre este arquivo abre no Excel em pt-BR, onde
     * a vírgula é separador decimal. Por isso o delimitador é ponto e vírgula.
     */
    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, ',', '.');
    }

    private function date(?string $value): string
    {
        return $value === null
            ? 'início'
            : CarbonImmutable::parse($value)->format('d/m/Y');
    }

    private function customerName(BillingReportFilters $filters): string
    {
        if ($filters->customerId === null) {
            return 'Todos';
        }

        return Customer::query()->find($filters->customerId)?->name
            ?? "#{$filters->customerId}";
    }
}

<?php

namespace App\Domain\Report;

use App\Models\Billing;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The CSV report export.
 *
 * It writes row by row into `php://output` while walking the result with `lazy()`. At no point
 * does the set exist whole in memory — that is what makes it possible to export a scope of
 * hundreds of thousands of billings without
 * estourar o processo.
 *
 * It uses the SAME BillingReportQuery and the SAME filters object as the screen. That is what
 * guarantees the exported file is the report the user is looking at, and not a second, similar
 * query.
 */
final class BillingReportCsvExport
{
    /** Rows per chunk read from the database. */
    private const CHUNK = 1_000;

    /** Every N rows the buffer is flushed, so the download starts right away. */
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
                // Without this, proxies and the browser itself may try to buffer.
                'X-Accel-Buffering' => 'no',
                'Cache-Control' => 'no-store',
            ],
        );
    }

    private function write(BillingReportFilters $filters): void
    {
        $out = fopen('php://output', 'wb');

        // BOM: without it Excel opens UTF-8 as Latin-1 and the accents turn to garbage. It is
        // the program whoever receives this file will use.
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
     * The applied period and filters, at the top of the file.
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
     * The totals in the footer, from the aggregation query over the whole filtered set — not
     * the sum of the rows that have just been written.
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
     * Brazilian format: whoever opens this file opens it in Excel in pt-BR, where the comma is
     * the decimal separator. That is why the delimiter is a semicolon.
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

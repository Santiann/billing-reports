<?php

namespace App\Domain\Report;

use App\Models\Customer;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportação do relatório em PDF.
 *
 * Diferente do CSV, aqui NÃO há streaming — e isso é da natureza do formato,
 * não descuido. Um PDF precisa ser paginado e montado inteiro antes de existir:
 * não há como emitir a página 1 sem saber quantas páginas haverá. O documento
 * ocupa memória proporcional ao número de linhas.
 *
 * É por isso que existe um teto, verificado ANTES de qualquer linha ser
 * carregada. Acima dele a resposta é 422 apontando o CSV, que não tem limite.
 */
final class BillingReportPdfExport
{
    public function __construct(private readonly BillingReportQuery $report) {}

    public function maxRows(): int
    {
        return (int) config('reports.pdf_max_rows', 5000);
    }

    public function exceedsLimit(int $count): bool
    {
        return $count > $this->maxRows();
    }

    /**
     * @param  array<string, mixed>  $totals
     */
    public function stream(BillingReportFilters $filters, array $totals): StreamedResponse
    {
        // get() e não lazy(): o dompdf precisa do conjunto inteiro de qualquer
        // forma. O teto é o que torna isso seguro.
        $billings = $this->report->rows($filters)->get();

        $pdf = Pdf::loadView('reports.billings', [
            'billings' => $billings,
            'totals' => $totals,
            'filters' => $filters,
            'period' => $this->period($filters),
            'customerName' => $this->customerName($filters),
            'generatedAt' => CarbonImmutable::now()->format('d/m/Y H:i'),
            'money' => fn (mixed $value): string => number_format((float) $value, 2, ',', '.'),
        ])->setPaper('a4', 'landscape');

        $filename = 'relatorio-faturamento-'
            .CarbonImmutable::now()->format('Y-m-d-His').'.pdf';

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $filename,
            ['Content-Type' => 'application/pdf', 'Cache-Control' => 'no-store'],
        );
    }

    private function period(BillingReportFilters $filters): string
    {
        return $this->date($filters->startDate).' a '.$this->date($filters->endDate);
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

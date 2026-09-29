<?php

namespace App\Domain\Report;

use App\Models\Customer;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The PDF report export.
 *
 * Unlike the CSV, there is NO streaming here — and that is the nature of the format, not
 * carelessness. A PDF has to be paginated and assembled whole before it exists: there is no way
 * to emit page 1 without knowing how many pages there will be. The document takes memory
 * proportional to the number of rows.
 *
 * That is why a cap exists, checked BEFORE any row is loaded. Above it the response is a 422
 * pointing at the CSV, which has no limit.
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
        // get() and not lazy(): dompdf needs the whole set either way. The cap is what makes
        // that safe.
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

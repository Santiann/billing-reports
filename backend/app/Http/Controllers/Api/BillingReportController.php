<?php

namespace App\Http\Controllers\Api;

use App\Domain\Report\BillingReportPdfExport;
use App\Domain\Report\BillingReportQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Report\BillingReportRequest;
use App\Http\Resources\BillingResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BillingReportController extends Controller
{
    public function __invoke(
        BillingReportRequest $request,
        BillingReportQuery $report,
        BillingReportPdfExport $pdf,
    ): AnonymousResourceCollection {
        $filters = $request->filters();

        $billings = $report->rows($filters)
            ->paginate($request->validated('per_page') ?? 25)
            ->withQueryString();

        // Agregação separada, sobre o conjunto filtrado inteiro.
        $totals = $report->totals($filters);

        return BillingResource::collection($billings)->additional([
            'totals' => $totals,
            // Eco dos filtros: a tela reexibe e as exportações imprimem no
            // cabeçalho do arquivo, a partir da mesma fonte.
            'filters' => $filters->toArray(),
            // O teto do PDF desce junto para a tela poder avisar ANTES do
            // clique, em vez de mandar o usuário para um 422.
            'export' => [
                'pdf_max_rows' => $pdf->maxRows(),
                'pdf_available' => ! $pdf->exceedsLimit($totals['count']),
            ],
        ]);
    }
}

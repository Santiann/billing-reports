<?php

namespace App\Http\Controllers\Api;

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
    ): AnonymousResourceCollection {
        $filters = $request->filters();

        $billings = $report->rows($filters)
            ->paginate($request->validated('per_page') ?? 25)
            ->withQueryString();

        return BillingResource::collection($billings)->additional([
            // Agregação separada, sobre o conjunto filtrado inteiro.
            'totals' => $report->totals($filters),
            // Eco dos filtros: a tela reexibe e as exportações imprimem no
            // cabeçalho do arquivo, a partir da mesma fonte.
            'filters' => $filters->toArray(),
        ]);
    }
}

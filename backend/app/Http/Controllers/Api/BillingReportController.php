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

        // A separate aggregation, over the whole filtered set.
        $totals = $report->totals($filters);

        return BillingResource::collection($billings)->additional([
            'totals' => $totals,
            // An echo of the filters: the screen re-displays them and the exports print them in
            // the file's header, all from the same source.
            'filters' => $filters->toArray(),
            // The PDF cap comes down too so the screen can warn BEFORE the click, instead of
            // sending the user into a 422.
            'export' => [
                'pdf_max_rows' => $pdf->maxRows(),
                'pdf_available' => ! $pdf->exceedsLimit($totals['count']),
            ],
        ]);
    }
}

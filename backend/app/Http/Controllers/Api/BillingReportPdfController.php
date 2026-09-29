<?php

namespace App\Http\Controllers\Api;

use App\Domain\Report\BillingReportPdfExport;
use App\Domain\Report\BillingReportQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Report\BillingReportRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BillingReportPdfController extends Controller
{
    public function __invoke(
        BillingReportRequest $request,
        BillingReportQuery $report,
        BillingReportPdfExport $export,
    ): StreamedResponse|JsonResponse {
        $filters = $request->filters();

        // The totals come first because their count is what decides whether the PDF can be
        // generated — and they are reused in the footer, with no extra query. Counting this way
        // avoids loading a single row only to discover there are too many.
        $totals = $report->totals($filters);

        if ($export->exceedsLimit($totals['count'])) {
            return response()->json([
                'message' => sprintf(
                    'O relatório tem %s cobranças e o limite do PDF é %s. '
                    .'Use a exportação em CSV, que não tem limite.',
                    number_format($totals['count'], 0, ',', '.'),
                    number_format($export->maxRows(), 0, ',', '.'),
                ),
                'count' => $totals['count'],
                'limit' => $export->maxRows(),
            ], 422);
        }

        return $export->stream($filters, $totals);
    }
}

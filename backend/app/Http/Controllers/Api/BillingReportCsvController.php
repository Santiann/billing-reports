<?php

namespace App\Http\Controllers\Api;

use App\Domain\Report\BillingReportCsvExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Report\BillingReportRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BillingReportCsvController extends Controller
{
    /**
     * The same FormRequest as the report: the accepted filters are exactly the same, and
     * validating them somewhere else would leave room for them to drift apart.
     */
    public function __invoke(
        BillingReportRequest $request,
        BillingReportCsvExport $export,
    ): StreamedResponse {
        return $export->stream($request->filters());
    }
}

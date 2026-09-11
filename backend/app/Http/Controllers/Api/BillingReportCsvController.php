<?php

namespace App\Http\Controllers\Api;

use App\Domain\Report\BillingReportCsvExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Report\BillingReportRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BillingReportCsvController extends Controller
{
    /**
     * Mesmo FormRequest do relatório: os filtros aceitos são exatamente os
     * mesmos, e validá-los em outro lugar abriria espaço para divergirem.
     */
    public function __invoke(
        BillingReportRequest $request,
        BillingReportCsvExport $export,
    ): StreamedResponse {
        return $export->stream($request->filters());
    }
}

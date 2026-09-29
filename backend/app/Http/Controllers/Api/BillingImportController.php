<?php

namespace App\Http\Controllers\Api;

use App\Domain\Billing\BillingCsvImport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Import\ImportRequest;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class BillingImportController extends Controller
{
    public function __invoke(ImportRequest $request, BillingCsvImport $import): JsonResponse
    {
        $path = $request->file('file')->getRealPath();

        try {
            $report = $request->isPreview()
                ? $import->preview($path)
                : $import->import($path);
        } catch (RuntimeException $error) {
            // A header missing the required columns is an error for the whole file, not for one
            // row: there is nothing to import partially.
            return response()->json([
                'message' => $error->getMessage(),
                'errors' => ['file' => [$error->getMessage()]],
            ], 422);
        }

        return response()->json($report->toArray());
    }
}

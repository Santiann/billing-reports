<?php

namespace App\Http\Controllers\Api;

use App\Domain\Customer\CustomerCsvImport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Import\ImportRequest;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class CustomerImportController extends Controller
{
    public function __invoke(ImportRequest $request, CustomerCsvImport $import): JsonResponse
    {
        $path = $request->file('file')->getRealPath();

        try {
            $report = $request->isPreview()
                ? $import->preview($path)
                : $import->import($path);
        } catch (RuntimeException $error) {
            // A file missing the required columns is an error for the WHOLE FILE, not for one
            // row: there is nothing to import partially. It comes back as a 422 on the upload
            // field, which is where the form knows how to show it.
            return response()->json([
                'message' => $error->getMessage(),
                'errors' => ['file' => [$error->getMessage()]],
            ], 422);
        }

        return response()->json($report->toArray());
    }
}

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
            // Arquivo sem as colunas exigidas é erro do ARQUIVO INTEIRO, não de
            // uma linha: não há o que importar parcialmente. Volta como 422 no
            // campo do upload, que é onde o formulário sabe exibir.
            return response()->json([
                'message' => $error->getMessage(),
                'errors' => ['file' => [$error->getMessage()]],
            ], 422);
        }

        return response()->json($report->toArray());
    }
}

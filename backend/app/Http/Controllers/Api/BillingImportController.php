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
        $caminho = $request->file('file')->getRealPath();

        try {
            $relatorio = $request->isPreview()
                ? $import->preview($caminho)
                : $import->import($caminho);
        } catch (RuntimeException $erro) {
            // Cabeçalho sem as colunas exigidas é erro do arquivo inteiro, não
            // de uma linha: não há o que importar parcialmente.
            return response()->json([
                'message' => $erro->getMessage(),
                'errors' => ['file' => [$erro->getMessage()]],
            ], 422);
        }

        return response()->json($relatorio->toArray());
    }
}

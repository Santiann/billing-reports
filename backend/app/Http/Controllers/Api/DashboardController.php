<?php

namespace App\Http\Controllers\Api;

use App\Domain\Dashboard\DashboardQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(DashboardQuery $dashboard): JsonResponse
    {
        return response()->json([
            'period' => $dashboard->period(),
            'monthly' => $dashboard->monthly(),
        ]);
    }
}

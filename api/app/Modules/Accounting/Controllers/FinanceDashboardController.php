<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\Dashboard\Services\FinanceDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceDashboardController
{
    public function __construct(private readonly FinanceDashboardService $service) {}

    public function summary(Request $request): JsonResponse
    {
        // The caller is needed, not optional: the service gates each panel on
        // the viewer's grants and keys its cache by the answers.
        return response()->json(['data' => $this->service->summary($request->user())]);
    }
}

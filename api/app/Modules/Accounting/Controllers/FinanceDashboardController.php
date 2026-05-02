<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Services\FinanceDashboardService;
use Illuminate\Http\JsonResponse;

class FinanceDashboardController
{
    public function __construct(private readonly FinanceDashboardService $service) {}

    public function summary(): JsonResponse
    {
        return response()->json(['data' => $this->service->summary()]);
    }
}

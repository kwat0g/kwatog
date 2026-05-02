<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Modules\Inventory\Services\InventoryDashboardService;
use Illuminate\Http\JsonResponse;

class InventoryDashboardController
{
    public function __construct(private readonly InventoryDashboardService $service) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->service->summary()]);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Controllers;

use App\Modules\Accounting\Models\Bill;
use App\Modules\Purchasing\Services\ThreeWayMatchService;
use Illuminate\Http\JsonResponse;

class ThreeWayMatchController
{
    public function __construct(private readonly ThreeWayMatchService $service) {}

    public function show(Bill $bill): JsonResponse
    {
        $result = $this->service->matchForBill($bill);
        return response()->json([
            'data' => $result?->toArray(),
        ]);
    }
}

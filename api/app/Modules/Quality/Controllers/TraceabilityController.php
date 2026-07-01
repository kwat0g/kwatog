<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Modules\Quality\Services\TraceabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TraceabilityController
{
    public function __construct(private readonly TraceabilityService $service) {}

    /**
     * GET /quality/traceability/search?term=BATCH-... | LOT-... | <material-lot>
     */
    public function search(Request $request): JsonResponse
    {
        $term = (string) $request->query('term', '');
        $result = $this->service->search($term);
        return response()->json(['data' => $result]);
    }

    public function recallSimulation(Request $request): JsonResponse
    {
        $lotNumber = (string) $request->query('lot', '');
        $result = $this->service->simulateRecall($lotNumber);
        return response()->json(['data' => $result]);
    }
}

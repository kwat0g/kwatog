<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Modules\Quality\Services\DefectParetoService;
use App\Modules\Quality\Enums\InspectionStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sprint 7 — Task 63. Quality analytics endpoints.
 */
class AnalyticsController
{
    public function __construct(private readonly DefectParetoService $pareto) {}

    public function defectPareto(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from'       => ['nullable', 'date'],
            'to'         => ['nullable', 'date', 'after_or_equal:from'],
            'product_id' => ['nullable'],
            'stage'      => ['nullable', Rule::enum(InspectionStage::class)],
            'limit'      => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        // Decode product hash_id if supplied.
        if (! empty($filters['product_id']) && is_string($filters['product_id'])) {
            $filters['product_id'] = \App\Modules\CRM\Models\Product::tryDecodeHash($filters['product_id']);
        }

        return response()->json(['data' => $this->pareto->run($filters)]);
    }

    public function inspectionSummary(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'product_id' => ['nullable'],
            'stage' => ['nullable', Rule::enum(InspectionStage::class)],
        ]);
        if (! empty($filters['product_id']) && is_string($filters['product_id'])) {
            $filters['product_id'] = \App\Modules\CRM\Models\Product::tryDecodeHash($filters['product_id']);
        }
        return response()->json(['data' => $this->pareto->inspectionSummary($filters)]);
    }

    public function paretoDrillDown(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'parameter_name' => ['required', 'string', 'max:150'],
            'from'           => ['nullable', 'date'],
            'to'             => ['nullable', 'date', 'after_or_equal:from'],
            'product_id'     => ['nullable'],
            'stage'          => ['nullable', Rule::enum(InspectionStage::class)],
        ]);
        if (! empty($filters['product_id']) && is_string($filters['product_id'])) {
            $filters['product_id'] = \App\Modules\CRM\Models\Product::tryDecodeHash($filters['product_id']);
        }
        return response()->json([
            'data' => $this->pareto->inspectionsWithDefect($filters['parameter_name'], $filters),
        ]);
    }
}

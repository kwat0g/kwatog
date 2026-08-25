<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Services\DefectParetoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            'product_id' => ['nullable', 'string'],
            'stage'      => ['nullable', Rule::enum(InspectionStage::class)],
            'limit'      => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        // Decode product hash_id if supplied.
        $filters['product_id'] = $this->decodeProductFilter($filters['product_id'] ?? null);

        return response()->json(['data' => $this->pareto->run($filters)]);
    }

    public function inspectionSummary(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'product_id' => ['nullable', 'string'],
            'stage' => ['nullable', Rule::enum(InspectionStage::class)],
        ]);
        $filters['product_id'] = $this->decodeProductFilter($filters['product_id'] ?? null);
        return response()->json(['data' => $this->pareto->inspectionSummary($filters)]);
    }

    public function paretoDrillDown(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'parameter_name' => ['required', 'string', 'max:150'],
            'from'           => ['nullable', 'date'],
            'to'             => ['nullable', 'date', 'after_or_equal:from'],
            'product_id'     => ['nullable', 'string'],
            'stage'          => ['nullable', Rule::enum(InspectionStage::class)],
        ]);
        $filters['product_id'] = $this->decodeProductFilter($filters['product_id'] ?? null);
        return response()->json([
            'data' => $this->pareto->inspectionsWithDefect($filters['parameter_name'], $filters),
        ]);
    }

    private function decodeProductFilter(?string $raw): ?int
    {
        if ($raw === null || $raw === '') return null;

        $decoded = Product::tryDecodeHash($raw);
        if ($decoded === null) {
            throw ValidationException::withMessages([
                'product_id' => ['The selected product filter is invalid or expired.'],
            ]);
        }

        return $decoded;
    }
}

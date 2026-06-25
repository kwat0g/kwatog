<?php

declare(strict_types=1);

namespace App\Modules\Forecasting\Controllers;

use App\Modules\CRM\Models\Product;
use App\Modules\Forecasting\Services\ForecastingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ForecastAccuracyController
{
    public function __construct(private readonly ForecastingService $service) {}

    /**
     * GET /forecasting/accuracy/summary
     * Overall MAPE, bias, and monthly breakdown for a year.
     */
    public function summary(Request $request): JsonResponse
    {
        $year = $request->integer('year', (int) now()->year);
        return response()->json(['data' => $this->service->accuracy($year)]);
    }

    /**
     * GET /forecasting/accuracy/products
     * Per-product accuracy breakdown (only active products with evaluated data).
     */
    public function byProduct(Request $request): JsonResponse
    {
        $year = $request->integer('year', (int) now()->year);
        $products = Product::where('is_active', true)->get();

        $results = $products->map(function ($product) use ($year) {
            $acc = $this->service->accuracy($year, $product->id);
            if ($acc['periods_evaluated'] === 0) {
                return null;
            }
            return [
                'product_id'        => $product->hash_id,
                'part_number'       => $product->part_number,
                'name'              => $product->name,
                'mape'              => $acc['mape'],
                'bias'              => $acc['bias'],
                'periods_evaluated' => $acc['periods_evaluated'],
            ];
        })->filter()->values();

        return response()->json(['data' => $results]);
    }
}

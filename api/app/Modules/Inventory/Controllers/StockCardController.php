<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Common\Support\HashIdFilter;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Series F — Task F3. Per-item stock card.
 *
 * GET /api/v1/inventory/items/{item}/stock-card?from=&to=&location_id=
 */
class StockCardController
{
    public function __construct(private readonly StockCardService $service) {}

    public function show(Request $request, Item $item): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'location_id' => ['nullable', 'string'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $to = $request->filled('to')
            ? Carbon::parse((string) $request->query('to'))->endOfDay()
            : Carbon::now()->endOfDay();
        $from = $request->filled('from')
            ? Carbon::parse((string) $request->query('from'))->startOfDay()
            : $to->copy()->subMonth()->startOfDay();

        $locationId = null;
        if ($request->filled('location_id')) {
            $locationId = HashIdFilter::decode($request->query('location_id'), WarehouseLocation::class);
            if (! $locationId || ! WarehouseLocation::query()->whereKey($locationId)->exists()) {
                throw ValidationException::withMessages(['location_id' => 'Choose a valid location.']);
            }
        }

        return response()->json([
            'data' => $this->service->card($item, $from, $to, $locationId, (int) $request->input('page', 1), (int) $request->input('per_page', 50)),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Modules\Inventory\Resources\StockCountItemResource;
use App\Modules\Inventory\Resources\StockCountSessionResource;
use App\Modules\Inventory\Services\StockCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

class StockCountController
{
    public function __construct(private readonly StockCountService $service) {}

    public function index(): AnonymousResourceCollection
    {
        return StockCountSessionResource::collection($this->service->listSessions());
    }

    public function show(int $id): StockCountSessionResource
    {
        return new StockCountSessionResource($this->service->getSession($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'        => 'required|string|max:200',
            'scope'        => 'required|in:full,warehouse,zone',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'zone_id'      => 'nullable|exists:warehouse_zones,id',
        ]);

        try {
            $session = $this->service->createSession($data, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new StockCountSessionResource($session))
            ->response()->setStatusCode(201);
    }

    public function start(int $id, Request $request): StockCountSessionResource
    {
        try {
            $session = $this->service->startSession($id, $request->user());
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        return new StockCountSessionResource($session);
    }

    public function recordCount(int $id, Request $request): StockCountItemResource
    {
        $data = $request->validate([
            'counted_quantity' => 'required|numeric|min:0',
            'lot_number'       => 'nullable|string|max:50',
            'notes'            => 'nullable|string|max:500',
        ]);

        try {
            $item = $this->service->recordCount($id, $data, $request->user());
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        return new StockCountItemResource($item);
    }

    public function approveVariance(int $id, Request $request): StockCountItemResource
    {
        try {
            $item = $this->service->approveVariance($id, $request->user());
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        return new StockCountItemResource($item);
    }

    public function complete(int $id, Request $request): StockCountSessionResource
    {
        try {
            $session = $this->service->completeSession($id, $request->user());
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        return new StockCountSessionResource($session);
    }

    public function cancel(int $id): JsonResponse
    {
        try {
            $this->service->cancelSession($id);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        return response()->json(null, 204);
    }
}

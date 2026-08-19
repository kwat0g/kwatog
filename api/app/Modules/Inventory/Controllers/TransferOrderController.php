<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\HashIdFilter;
use App\Modules\Accounting\Exceptions\ClosedPeriodException;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\InvalidMovementException;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\TransferOrder;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Resources\TransferOrderResource;
use App\Modules\Inventory\Services\TransferOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TransferOrderController
{
    public function __construct(private readonly TransferOrderService $service) {}

    /**
     * Route params here are plain `{id}`, not model-bound, so nothing decodes
     * the hash for us. Type-hinting `int $id` made every hash a TypeError (500);
     * this resolves the hash the same way route-model binding would, 404ing on
     * an unknown or malformed one.
     */
    private function orderId(string $id): int
    {
        return HashIdFilter::decode($id, TransferOrder::class) ?? abort(404);
    }

    public function index(): AnonymousResourceCollection
    {
        return TransferOrderResource::collection($this->service->list());
    }

    public function show(string $id): TransferOrderResource
    {
        return new TransferOrderResource($this->service->get($this->orderId($id)));
    }

    public function store(Request $request): JsonResponse
    {
        // Decoded before validation: `exists:` compares against a bigint column,
        // so an undecoded hash reaches Postgres as 22P02 — a 500, not a 422.
        // Failing to null (never 0) keeps a malformed hash a validation error
        // instead of a foreign key of 0.
        $request->merge([
            'from_location_id' => HashIdFilter::decode($request->input('from_location_id'), WarehouseLocation::class),
            'to_location_id'   => HashIdFilter::decode($request->input('to_location_id'), WarehouseLocation::class),
            'item_id'          => HashIdFilter::decode($request->input('item_id'), Item::class),
        ]);

        $data = $request->validate([
            'from_location_id' => 'required|integer|exists:warehouse_locations,id',
            'to_location_id'   => 'required|integer|exists:warehouse_locations,id',
            'item_id'          => 'required|integer|exists:items,id',
            'quantity'         => 'required|numeric|min:0.001',
            'reason'           => 'nullable|string|max:200',
        ]);

        try {
            $order = $this->service->create($data, $request->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new TransferOrderResource($order->load([
            'fromLocation.zone.warehouse', 'toLocation.zone.warehouse', 'item', 'creator',
        ])))->response()->setStatusCode(201);
    }

    public function execute(string $id, Request $request): TransferOrderResource
    {
        // Kept outside the try. This used to be load-bearing: abort(404) raises
        // NotFoundHttpException, which extends RuntimeException, so the old
        // `catch (RuntimeException)` rewrote an unknown id as a 422. The catch is
        // now narrowed to the business rules, so the 404 would survive either
        // way — but resolving before the try still says which failures belong to
        // the id and which to the session's state.
        $orderId = $this->orderId($id);

        try {
            $order = $this->service->execute($orderId, $request->user());
        } catch (BusinessRuleException|ClosedPeriodException|InsufficientStockException|InvalidMovementException $e) {
            abort(422, $e->getMessage());
        }
        return new TransferOrderResource($order);
    }

    public function cancel(string $id): JsonResponse
    {
        $orderId = $this->orderId($id);

        try {
            $this->service->cancel($orderId);
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }
        return response()->json(null, 204);
    }
}

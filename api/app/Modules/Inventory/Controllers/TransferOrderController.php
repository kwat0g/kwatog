<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Common\Support\HashIdFilter;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Resources\TransferOrderResource;
use App\Modules\Inventory\Services\TransferOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

class TransferOrderController
{
    public function __construct(private readonly TransferOrderService $service) {}

    public function index(): AnonymousResourceCollection
    {
        return TransferOrderResource::collection($this->service->list());
    }

    public function show(int $id): TransferOrderResource
    {
        return new TransferOrderResource($this->service->get($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_location_id' => 'required|string',
            'to_location_id'   => 'required|string',
            'item_id'          => 'required|string',
            'quantity'         => 'required|numeric|min:0.001',
            'reason'           => 'nullable|string|max:200',
        ]);

        $data['from_location_id'] = HashIdFilter::decode($data['from_location_id'], WarehouseLocation::class) ?? (int) $data['from_location_id'];
        $data['to_location_id']   = HashIdFilter::decode($data['to_location_id'], WarehouseLocation::class) ?? (int) $data['to_location_id'];
        $data['item_id']          = HashIdFilter::decode($data['item_id'], Item::class) ?? (int) $data['item_id'];

        try {
            $order = $this->service->create($data, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new TransferOrderResource($order->load([
            'fromLocation.zone.warehouse', 'toLocation.zone.warehouse', 'item', 'creator',
        ])))->response()->setStatusCode(201);
    }

    public function execute(int $id, Request $request): TransferOrderResource
    {
        try {
            $order = $this->service->execute($id, $request->user());
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        return new TransferOrderResource($order);
    }

    public function cancel(int $id): JsonResponse
    {
        try {
            $this->service->cancel($id);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        return response()->json(null, 204);
    }
}

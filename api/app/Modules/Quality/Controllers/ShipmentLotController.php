<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\ShipmentLot;
use App\Modules\SupplyChain\Resources\ShipmentLotResource;
use App\Modules\SupplyChain\Services\ShipmentLotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShipmentLotController
{
    public function __construct(private readonly ShipmentLotService $service) {}

    /**
     * GET /quality/traceability/deliveries/{delivery}/shipment-lot
     * Returns the most recent lot for the given delivery, or null.
     */
    public function showForDelivery(Delivery $delivery): JsonResponse
    {
        $lot = $this->service->showForDelivery($delivery);
        if (! $lot) {
            return response()->json(['data' => null]);
        }
        return (new ShipmentLotResource($lot))->response();
    }

    /**
     * POST /quality/traceability/deliveries/{delivery}/shipment-lot
     * Body: { work_order_ids: [hash, ...], quantity?: int, lot_date?: YYYY-MM-DD }
     */
    public function createForDelivery(Request $request, Delivery $delivery): JsonResponse
    {
        $data = $request->validate([
            'work_order_ids'   => ['required', 'array', 'min:1'],
            'work_order_ids.*' => ['required', 'string'],
            'quantity'         => ['nullable', 'integer', 'min:1'],
            'lot_date'         => ['nullable', 'date'],
        ]);

        $lot = $this->service->createForDelivery($delivery, $data, $request->user());
        return (new ShipmentLotResource($lot))->response()->setStatusCode(201);
    }

    public function show(ShipmentLot $shipmentLot): ShipmentLotResource
    {
        return new ShipmentLotResource($this->service->show($shipmentLot));
    }
}

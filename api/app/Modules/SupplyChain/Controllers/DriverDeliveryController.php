<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Controllers;

use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Requests\DriverUpdateStatusRequest;
use App\Modules\SupplyChain\Requests\DriverUploadReceiptRequest;
use App\Modules\SupplyChain\Resources\DeliveryResource;
use App\Modules\SupplyChain\Services\DriverDeliveryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DriverDeliveryController
{
    public function __construct(private readonly DriverDeliveryService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return DeliveryResource::collection(
            $this->service->list($request->user(), $request->all()),
        );
    }

    public function show(Request $request, Delivery $delivery): DeliveryResource
    {
        return new DeliveryResource(
            $this->service->show($request->user(), $delivery),
        );
    }

    public function updateStatus(DriverUpdateStatusRequest $request, Delivery $delivery): DeliveryResource
    {
        return new DeliveryResource(
            $this->service->updateStatus(
                $request->user(),
                $delivery,
                (string) $request->validated('status'),
            ),
        );
    }

    public function uploadReceipt(DriverUploadReceiptRequest $request, Delivery $delivery): DeliveryResource
    {
        return new DeliveryResource(
            $this->service->uploadReceipt(
                $request->user(),
                $delivery,
                $request->file('photo'),
            ),
        );
    }
}

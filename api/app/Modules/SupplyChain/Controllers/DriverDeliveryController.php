<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Controllers;

use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Requests\DriverUpdateStatusRequest;
use App\Modules\SupplyChain\Requests\DriverUploadReceiptRequest;
use App\Modules\SupplyChain\Requests\StoreDeliveryAttemptOutcomeRequest;
use App\Modules\SupplyChain\Resources\DriverDeliveryResource;
use App\Modules\SupplyChain\Services\DeliveryAttemptOutcomeService;
use App\Modules\SupplyChain\Services\DriverDeliveryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DriverDeliveryController
{
    public function __construct(
        private readonly DriverDeliveryService $service,
        private readonly DeliveryAttemptOutcomeService $attemptOutcomes,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return DriverDeliveryResource::collection(
            $this->service->list($request->user(), $request->all()),
        );
    }

    public function show(Request $request, Delivery $delivery): DriverDeliveryResource
    {
        return new DriverDeliveryResource(
            $this->service->show($request->user(), $delivery),
        );
    }

    public function updateStatus(DriverUpdateStatusRequest $request, Delivery $delivery): DriverDeliveryResource
    {
        return new DriverDeliveryResource(
            $this->service->updateStatus(
                $request->user(),
                $delivery,
                (string) $request->validated('status'),
            ),
        );
    }

    public function uploadReceipt(DriverUploadReceiptRequest $request, Delivery $delivery): DriverDeliveryResource
    {
        return new DriverDeliveryResource(
            $this->service->uploadReceipt(
                $request->user(),
                $delivery,
                $request->file('photo'),
            ),
        );
    }

    public function amendAttemptOutcome(\App\Modules\SupplyChain\Requests\AmendDeliveryAttemptRequest $request, Delivery $delivery): DriverDeliveryResource
    {
        return new DriverDeliveryResource($this->attemptOutcomes->amend($delivery, $request->user(), $request->validated(), true));
    }

    public function reportAttemptOutcome(StoreDeliveryAttemptOutcomeRequest $request, Delivery $delivery): DriverDeliveryResource
    {
        return new DriverDeliveryResource($this->attemptOutcomes->report(
            $delivery,
            $request->user(),
            $request->validated(),
            driverSurface: true,
        ));
    }
}

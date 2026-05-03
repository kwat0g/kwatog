<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Controllers;

use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Requests\CreateDeliveryRequest;
use App\Modules\SupplyChain\Resources\DeliveryResource;
use App\Modules\SupplyChain\Services\DeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class DeliveryController
{
    public function __construct(private readonly DeliveryService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return DeliveryResource::collection($this->service->list($request->query()));
    }

    public function show(Delivery $delivery): DeliveryResource
    {
        return new DeliveryResource($this->service->show($delivery));
    }

    public function store(CreateDeliveryRequest $request): DeliveryResource
    {
        return new DeliveryResource($this->service->create($request->validated(), $request->user()));
    }

    public function updateStatus(Request $request, Delivery $delivery): DeliveryResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(DeliveryStatus::values())],
            'note'   => ['nullable', 'string', 'max:500'],
        ]);
        return new DeliveryResource($this->service->updateStatus(
            $delivery,
            DeliveryStatus::from((string) $data['status']),
            $data['note'] ?? null,
        ));
    }

    public function uploadReceipt(Request $request, Delivery $delivery): DeliveryResource
    {
        $request->validate(['file' => ['required', 'image', 'max:10240']]);
        return new DeliveryResource(
            $this->service->uploadReceiptPhoto($delivery, $request->file('file'))
        );
    }

    public function confirm(Request $request, Delivery $delivery): DeliveryResource
    {
        return new DeliveryResource($this->service->confirm($delivery, $request->user()));
    }

    public function destroy(Delivery $delivery): JsonResponse
    {
        $this->service->delete($delivery);
        return response()->json([], 204);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Controllers;

use App\Modules\SupplyChain\Enums\ShipmentDocumentType;
use App\Modules\SupplyChain\Enums\ShipmentStatus;
use App\Modules\SupplyChain\Models\Shipment;
use App\Modules\SupplyChain\Models\ShipmentDocument;
use App\Modules\SupplyChain\Requests\CreateShipmentRequest;
use App\Modules\SupplyChain\Resources\ShipmentDocumentResource;
use App\Modules\SupplyChain\Resources\ShipmentResource;
use App\Modules\SupplyChain\Services\ShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ShipmentController
{
    public function __construct(private readonly ShipmentService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ShipmentResource::collection($this->service->list($request->query()));
    }

    public function show(Shipment $shipment): ShipmentResource
    {
        return new ShipmentResource($this->service->show($shipment));
    }

    public function store(CreateShipmentRequest $request): ShipmentResource
    {
        return new ShipmentResource($this->service->create($request->validated(), $request->user()));
    }

    public function updateStatus(Request $request, Shipment $shipment): ShipmentResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(ShipmentStatus::values())],
            'note'   => ['nullable', 'string', 'max:500'],
        ]);
        return new ShipmentResource($this->service->updateStatus(
            $shipment,
            ShipmentStatus::from((string) $data['status']),
            $data['note'] ?? null,
        ));
    }

    public function updateMeta(Request $request, Shipment $shipment): ShipmentResource
    {
        $data = $request->validate([
            'carrier'          => ['nullable', 'string', 'max:100'],
            'vessel'           => ['nullable', 'string', 'max:100'],
            'container_number' => ['nullable', 'string', 'max:32'],
            'bl_number'        => ['nullable', 'string', 'max:32'],
            'etd'              => ['nullable', 'date'],
            'eta'              => ['nullable', 'date'],
            'notes'            => ['nullable', 'string', 'max:2000'],
        ]);
        return new ShipmentResource($this->service->updateMeta($shipment, $data));
    }

    public function uploadDocument(Request $request, Shipment $shipment): ShipmentDocumentResource
    {
        $data = $request->validate([
            'document_type' => ['required', Rule::in(ShipmentDocumentType::values())],
            'file'          => ['required', 'file', 'max:20480'], // 20 MB
            'notes'         => ['nullable', 'string', 'max:500'],
        ]);
        $doc = $this->service->uploadDocument(
            $shipment,
            $request->file('file'),
            ShipmentDocumentType::from((string) $data['document_type']),
            $request->user(),
            $data['notes'] ?? null,
        );
        return new ShipmentDocumentResource($doc);
    }

    public function destroyDocument(ShipmentDocument $document): JsonResponse
    {
        $this->service->deleteDocument($document);
        return response()->json([], 204);
    }

    public function destroy(Shipment $shipment): JsonResponse
    {
        $this->service->delete($shipment);
        return response()->json([], 204);
    }
}

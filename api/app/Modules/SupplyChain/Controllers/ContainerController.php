<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Controllers;

use App\Modules\SupplyChain\Models\Container;
use App\Modules\SupplyChain\Models\Shipment;
use App\Modules\SupplyChain\Resources\ContainerResource;
use App\Modules\SupplyChain\Services\ContainerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ContainerController
{
    public function __construct(private readonly ContainerService $service) {}

    public function index(Request $request, Shipment $shipment): AnonymousResourceCollection
    {
        return ContainerResource::collection(
            $this->service->listByShipment($shipment, $request->only(['search', 'per_page']))
        );
    }

    public function store(Request $request, Shipment $shipment): ContainerResource
    {
        $data = $request->validate([
            'container_number' => ['required', 'string', 'max:50'],
            'seal_number'      => ['nullable', 'string', 'max:50'],
            'size'             => ['nullable', Rule::in(\App\Modules\SupplyChain\Enums\ContainerSize::values())],
            'type'             => ['nullable', Rule::in(\App\Modules\SupplyChain\Enums\ContainerType::values())],
            'gross_weight_kg'  => ['nullable', 'numeric', 'min:0'],
            'net_weight_kg'    => ['nullable', 'numeric', 'min:0'],
            'volume_cbm'       => ['nullable', 'numeric', 'min:0'],
            'notes'            => ['nullable', 'string', 'max:1000'],
        ]);

        return new ContainerResource($this->service->create($shipment, $data));
    }

    public function show(Container $container): ContainerResource
    {
        return new ContainerResource($this->service->show($container));
    }

    public function update(Request $request, Container $container): ContainerResource
    {
        $data = $request->validate([
            'container_number' => ['nullable', 'string', 'max:50'],
            'seal_number'      => ['nullable', 'string', 'max:50'],
            'size'             => ['nullable', Rule::in(\App\Modules\SupplyChain\Enums\ContainerSize::values())],
            'type'             => ['nullable', Rule::in(\App\Modules\SupplyChain\Enums\ContainerType::values())],
            'gross_weight_kg'  => ['nullable', 'numeric', 'min:0'],
            'net_weight_kg'    => ['nullable', 'numeric', 'min:0'],
            'volume_cbm'       => ['nullable', 'numeric', 'min:0'],
            'notes'            => ['nullable', 'string', 'max:1000'],
        ]);

        return new ContainerResource($this->service->update($container, $data));
    }

    public function destroy(Container $container): JsonResponse
    {
        $this->service->delete($container);
        return response()->json([], 204);
    }
}

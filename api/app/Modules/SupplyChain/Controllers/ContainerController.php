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
    /**
     * Physical-measure rules, shared by store() and update().
     *
     * `numeric|min:0` alone was the shape found in eight sibling modules and it
     * leaks three ways against these columns (`gross/net_weight_kg` numeric(10,2),
     * `volume_cbm` numeric(8,3)). Measured, one value per request:
     *   1.999    → accepted, silently stored as 2.00
     *   1e3      → accepted, silently stored as 1000.00
     *   1e17/1e20 → SQLSTATE 22003 numeric overflow, surfaced as a 500
     * `decimal:0,N` refuses both the extra precision and the exponent form (its
     * regex rejects `1e3` outright), and `max:` keeps a plain long number inside
     * the column precision instead of letting Postgres raise.
     *
     * @return array<int, string>
     */
    private static function weightRules(): array
    {
        return ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'];
    }

    /** @return array<int, string> */
    private static function volumeRules(): array
    {
        return ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:99999.999'];
    }

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
            'gross_weight_kg'  => self::weightRules(),
            'net_weight_kg'    => self::weightRules(),
            'volume_cbm'       => self::volumeRules(),
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
            'gross_weight_kg'  => self::weightRules(),
            'net_weight_kg'    => self::weightRules(),
            'volume_cbm'       => self::volumeRules(),
            'notes'            => ['nullable', 'string', 'max:1000'],
        ]);

        return new ContainerResource($this->service->update($container, $data));
    }

    public function destroy(Container $container): JsonResponse
    {
        $this->service->delete($container);
        return response()->json([], 204);
    }

    public function restore(Container $container): JsonResponse
    {
        $container->restore();
        return response()->json(['message' => 'Container restored.']);
    }
}

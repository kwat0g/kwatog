<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Controllers;

use App\Common\Support\SearchOperator;
use App\Modules\SupplyChain\Models\Vehicle;
use App\Modules\SupplyChain\Enums\VehicleType;
use App\Modules\SupplyChain\Enums\VehicleStatus;
use App\Modules\SupplyChain\Resources\VehicleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class VehicleController
{
    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'types' => array_map(static fn (VehicleType $type): array => ['value' => $type->value, 'label' => $type->label()], VehicleType::cases()),
            'statuses' => array_map(static fn (VehicleStatus $status): array => ['value' => $status->value, 'label' => $status->label()], VehicleStatus::cases()),
        ]]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Vehicle::query();
        if ($request->filled('status')) $q->where('status', $request->query('status'));
        if ($request->filled('search')) {
            $term = '%'.trim((string) $request->query('search')).'%';
            $q->where(fn ($b) => $b->where('plate_number', SearchOperator::like(), $term)->orWhere('name', SearchOperator::like(), $term));
        }
        return VehicleResource::collection(
            $q->orderBy('name')->paginate(min((int) $request->query('per_page', 50), 100))
        );
    }

    public function store(Request $request): VehicleResource
    {
        $data = $request->validate([
            'plate_number' => ['required', 'string', 'max:20', 'unique:vehicles,plate_number'],
            'name'         => ['required', 'string', 'max:100'],
            'vehicle_type' => ['required', Rule::enum(VehicleType::class)],
            'capacity_kg'  => ['nullable', 'numeric', 'min:0'],
            'status'       => ['nullable', Rule::enum(VehicleStatus::class)],
            'notes'        => ['nullable', 'string', 'max:500'],
        ]);
        return new VehicleResource(Vehicle::create($data));
    }

    public function update(Request $request, Vehicle $vehicle): VehicleResource
    {
        $data = $request->validate([
            'plate_number' => ['nullable', 'string', 'max:20', Rule::unique('vehicles', 'plate_number')->ignore($vehicle->id)],
            'name'         => ['nullable', 'string', 'max:100'],
            'vehicle_type' => ['nullable', Rule::enum(VehicleType::class)],
            'capacity_kg'  => ['nullable', 'numeric', 'min:0'],
            'status'       => ['nullable', Rule::enum(VehicleStatus::class)],
            'notes'        => ['nullable', 'string', 'max:500'],
        ]);
        $vehicle->update($data);
        return new VehicleResource($vehicle);
    }

    public function destroy(Vehicle $vehicle): JsonResponse
    {
        $vehicle->delete();
        return response()->json([], 204);
    }

    public function restore(Vehicle $vehicle): JsonResponse
    {
        $vehicle->restore();
        return response()->json(['message' => 'Vehicle restored.']);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Modules\Inventory\Models\Item;
use App\Modules\Quality\Models\ItemQualityPlan;
use App\Modules\Quality\Resources\ItemQualityPlanResource;
use App\Modules\Quality\Services\ItemQualityPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ItemQualityPlanController
{
    public function __construct(private readonly ItemQualityPlanService $service) {}

    public function index(Item $item): AnonymousResourceCollection
    {
        return ItemQualityPlanResource::collection($this->service->revisions($item));
    }

    public function store(Request $request, Item $item): JsonResponse
    {
        $data = $request->validate([
            'vendor_id' => ['nullable', 'string'],
            'sampling_method' => ['required', 'in:aql,fixed,full'],
            'fixed_sample_size' => ['nullable', 'required_if:sampling_method,fixed', 'integer', 'min:1', 'max:1000'],
            'aql_level' => ['nullable', 'string', 'max:20'],
            'parameters' => ['required', 'array', 'min:1', 'max:100'],
            'parameters.*.parameter_name' => ['required', 'string', 'max:150'],
            'parameters.*.parameter_type' => ['required', 'in:dimensional,visual,functional'],
            'parameters.*.unit_of_measure' => ['nullable', 'string', 'max:20'],
            'parameters.*.nominal_value' => ['nullable', 'numeric'],
            'parameters.*.tolerance_min' => ['nullable', 'numeric'],
            'parameters.*.tolerance_max' => ['nullable', 'numeric'],
            'parameters.*.is_critical' => ['sometimes', 'boolean'],
            'parameters.*.notes' => ['nullable', 'string', 'max:500'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return (new ItemQualityPlanResource($this->service->createRevision($item, $data, $request->user())))
            ->response()->setStatusCode(201);
    }

    public function deactivate(Request $request, ItemQualityPlan $itemQualityPlan): ItemQualityPlanResource
    {
        return new ItemQualityPlanResource($this->service->deactivate($itemQualityPlan, $request->user()));
    }
}

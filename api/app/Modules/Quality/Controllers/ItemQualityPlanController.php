<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Modules\Inventory\Models\Item;
use App\Common\Services\SettingsService;
use App\Modules\Quality\Models\ItemQualityPlan;
use App\Modules\Quality\Enums\InspectionParameterType;
use App\Modules\Quality\Enums\QualityPlanSamplingMethod;
use App\Modules\Quality\Resources\ItemQualityPlanResource;
use App\Modules\Quality\Services\ItemQualityPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ItemQualityPlanController
{
    public function __construct(
        private readonly ItemQualityPlanService $service,
        private readonly SettingsService $settings,
    ) {}

    public function index(Item $item): AnonymousResourceCollection
    {
        return ItemQualityPlanResource::collection($this->service->revisions($item));
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'sampling_methods' => array_map(
                static fn (QualityPlanSamplingMethod $method): array => ['value' => $method->value, 'label' => $method->label()],
                QualityPlanSamplingMethod::cases(),
            ),
            'parameter_types' => array_map(
                static fn (InspectionParameterType $type): array => ['value' => $type->value, 'label' => $type->label()],
                InspectionParameterType::cases(),
            ),
            'default_aql_level' => (string) $this->settings->get('quality.aql.default_level', ''),
        ]]);
    }

    public function store(Request $request, Item $item): JsonResponse
    {
        $data = $request->validate([
            'vendor_id' => ['nullable', 'string'],
            'sampling_method' => ['required', \Illuminate\Validation\Rule::enum(QualityPlanSamplingMethod::class)],
            'fixed_sample_size' => ['nullable', 'required_if:sampling_method,fixed', 'integer', 'min:1', 'max:1000'],
            'aql_level' => ['nullable', 'string', 'max:20'],
            'parameters' => ['required', 'array', 'min:1', 'max:100'],
            'parameters.*.parameter_name' => ['required', 'string', 'max:150'],
            'parameters.*.parameter_type' => ['required', \Illuminate\Validation\Rule::enum(InspectionParameterType::class)],
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

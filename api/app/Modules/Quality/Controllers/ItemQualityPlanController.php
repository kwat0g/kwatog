<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Common\Services\SettingsService;
use App\Modules\Inventory\Models\Item;
use App\Modules\Quality\Enums\InspectionParameterType;
use App\Modules\Quality\Enums\QualityPlanSamplingMethod;
use App\Modules\Quality\Models\ItemQualityPlan;
use App\Modules\Quality\Requests\StoreItemQualityPlanRequest;
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
            'checklist_capture_enabled' => (bool) config('inspection.checklist_capture_enabled'),
            'parameter_types' => array_map(
                static fn (InspectionParameterType $type): array => ['value' => $type->value, 'label' => $type->label()],
                InspectionParameterType::cases(),
            ),
            'default_aql_level' => (string) $this->settings->get('quality.aql.default_level', ''),
        ]]);
    }

    public function store(StoreItemQualityPlanRequest $request, Item $item): JsonResponse
    {
        $data = $request->validated();

        return (new ItemQualityPlanResource($this->service->createRevision($item, $data, $request->user())))
            ->response()->setStatusCode(201);
    }

    public function deactivate(Request $request, ItemQualityPlan $itemQualityPlan): ItemQualityPlanResource
    {
        return new ItemQualityPlanResource($this->service->deactivate($itemQualityPlan, $request->user()));
    }
}

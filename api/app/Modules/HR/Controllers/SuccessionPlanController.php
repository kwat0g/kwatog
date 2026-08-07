<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Models\SuccessionPlan;
use App\Modules\HR\Enums\SuccessionReadiness;
use App\Modules\HR\Enums\SuccessionPriority;
use App\Modules\HR\Enums\SuccessionStatus;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\HR\Requests\StoreSuccessionPlanRequest;
use App\Modules\HR\Requests\UpdateSuccessionPlanRequest;
use App\Modules\HR\Resources\SuccessionPlanResource;
use App\Modules\HR\Services\SuccessionPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class SuccessionPlanController extends Controller
{
    public function __construct(private readonly SuccessionPlanService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return SuccessionPlanResource::collection(
            $this->service->list($request->all())
        );
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'statuses' => array_map(
                static fn (SuccessionStatus $status): array => ['value' => $status->value, 'label' => ucfirst($status->value)],
                SuccessionStatus::cases(),
            ),
            'readiness' => array_map(
                static fn (SuccessionReadiness $readiness): array => ['value' => $readiness->value, 'label' => str_replace('_', ' ', ucfirst($readiness->value))],
                SuccessionReadiness::cases(),
            ),
            'priorities' => array_map(
                static fn (SuccessionPriority $priority): array => ['value' => $priority->value, 'label' => ucfirst($priority->value)],
                SuccessionPriority::cases(),
            ),
            'positions' => Position::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Position $position): array => ['value' => $position->hash_id, 'label' => $position->name])
                ->values()
                ->all(),
            'employees' => Employee::query()
                ->whereIn('status', [EmployeeStatus::Active->value, EmployeeStatus::OnLeave->value])
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix'])
                ->map(fn (Employee $employee): array => ['value' => $employee->hash_id, 'label' => $employee->full_name])
                ->values()
                ->all(),
        ]]);
    }

    public function store(StoreSuccessionPlanRequest $request): \Illuminate\Http\JsonResponse
    {
        return (new SuccessionPlanResource(
            $this->service->create($request->validated())
        ))->response()->setStatusCode(201);
    }

    public function show(SuccessionPlan $successionPlan): SuccessionPlanResource
    {
        return new SuccessionPlanResource(
            $successionPlan->load(['position:id,title', 'incumbent:id,first_name,last_name', 'successor:id,first_name,last_name'])
        );
    }

    public function update(UpdateSuccessionPlanRequest $request, SuccessionPlan $successionPlan): SuccessionPlanResource
    {
        return new SuccessionPlanResource(
            $this->service->update($successionPlan, $request->validated())
        );
    }

    public function destroy(SuccessionPlan $successionPlan): JsonResponse
    {
        $this->service->delete($successionPlan);
        return response()->json(null, 204);
    }

    public function restore(SuccessionPlan $successionPlan): JsonResponse
    {
        $successionPlan->restore();
        return response()->json(['message' => 'Succession plan restored.']);
    }
}

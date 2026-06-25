<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Models\SuccessionPlan;
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
}

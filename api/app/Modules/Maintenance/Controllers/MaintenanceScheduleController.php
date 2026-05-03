<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Controllers;

use App\Modules\Maintenance\Models\MaintenanceSchedule;
use App\Modules\Maintenance\Requests\StoreMaintenanceScheduleRequest;
use App\Modules\Maintenance\Requests\UpdateMaintenanceScheduleRequest;
use App\Modules\Maintenance\Resources\MaintenanceScheduleResource;
use App\Modules\Maintenance\Services\MaintenanceScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MaintenanceScheduleController
{
    public function __construct(private readonly MaintenanceScheduleService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return MaintenanceScheduleResource::collection($this->service->list($request->query()));
    }

    public function show(MaintenanceSchedule $schedule): MaintenanceScheduleResource
    {
        return new MaintenanceScheduleResource($this->service->show($schedule));
    }

    public function store(StoreMaintenanceScheduleRequest $request): JsonResponse
    {
        $schedule = $this->service->create($request->validated());
        return (new MaintenanceScheduleResource($schedule))->response()->setStatusCode(201);
    }

    public function update(UpdateMaintenanceScheduleRequest $request, MaintenanceSchedule $schedule): MaintenanceScheduleResource
    {
        return new MaintenanceScheduleResource($this->service->update($schedule, $request->validated()));
    }

    public function destroy(MaintenanceSchedule $schedule): JsonResponse
    {
        $this->service->delete($schedule);
        return response()->json(null, 204);
    }
}

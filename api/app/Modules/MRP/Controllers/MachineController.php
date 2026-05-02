<?php

declare(strict_types=1);

namespace App\Modules\MRP\Controllers;

use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Requests\StoreMachineRequest;
use App\Modules\MRP\Requests\TransitionMachineStatusRequest;
use App\Modules\MRP\Requests\UpdateMachineRequest;
use App\Modules\MRP\Resources\MachineResource;
use App\Modules\MRP\Services\MachineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MachineController
{
    public function __construct(private readonly MachineService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return MachineResource::collection($this->service->list($request->query()));
    }

    public function show(Machine $machine): MachineResource
    {
        return new MachineResource($this->service->show($machine));
    }

    public function store(StoreMachineRequest $request): JsonResponse
    {
        $m = $this->service->create($request->validated());
        return (new MachineResource($m))->response()->setStatusCode(201);
    }

    public function update(UpdateMachineRequest $request, Machine $machine): MachineResource
    {
        return new MachineResource($this->service->update($machine, $request->validated()));
    }

    public function destroy(Machine $machine): JsonResponse
    {
        $this->service->delete($machine);
        return response()->json(null, 204);
    }

    public function transitionStatus(TransitionMachineStatusRequest $request, Machine $machine): MachineResource
    {
        $to = MachineStatus::from($request->input('to'));
        $m = $this->service->transitionStatus($machine, $to, $request->input('reason'));
        return new MachineResource($m);
    }
}

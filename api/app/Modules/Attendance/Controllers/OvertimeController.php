<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Requests\ApproveOvertimeRequestRequest;
use App\Modules\Attendance\Requests\RejectOvertimeRequestRequest;
use App\Modules\Attendance\Requests\StoreOvertimeRequestRequest;
use App\Modules\Attendance\Resources\OvertimeRequestResource;
use App\Modules\Attendance\Services\OvertimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OvertimeController
{
    public function __construct(private readonly OvertimeService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return OvertimeRequestResource::collection($this->service->list($request->query(), $request->user()));
    }

    public function store(StoreOvertimeRequestRequest $request): JsonResponse
    {
        $ot = $this->service->create($request->validatedData());
        return (new OvertimeRequestResource($ot))->response()->setStatusCode(201);
    }

    public function show(OvertimeRequest $overtime): OvertimeRequestResource
    {
        return new OvertimeRequestResource($overtime->load(['employee', 'approver']));
    }

    public function approve(ApproveOvertimeRequestRequest $request, OvertimeRequest $overtime): OvertimeRequestResource
    {
        $ot = $this->service->approve($overtime, $request->user(), $request->input('remarks'));
        return new OvertimeRequestResource($ot);
    }

    public function reject(RejectOvertimeRequestRequest $request, OvertimeRequest $overtime): OvertimeRequestResource
    {
        $ot = $this->service->reject($overtime, $request->user(), $request->input('reason'));
        return new OvertimeRequestResource($ot);
    }
}

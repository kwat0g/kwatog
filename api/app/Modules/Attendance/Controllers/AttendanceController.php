<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Requests\ImportAttendanceRequest;
use App\Modules\Attendance\Requests\StoreAttendanceRequest;
use App\Modules\Attendance\Requests\UpdateAttendanceRequest;
use App\Modules\Attendance\Resources\AttendanceResource;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Attendance\Services\DTRImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AttendanceController
{
    public function __construct(
        private readonly AttendanceService $service,
        private readonly DTRImportService $importer,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return AttendanceResource::collection($this->service->list($request->query()));
    }

    public function store(StoreAttendanceRequest $request): JsonResponse
    {
        $a = $this->service->create($request->validatedData());
        return (new AttendanceResource($a))->response()->setStatusCode(201);
    }

    public function show(Attendance $attendance): AttendanceResource
    {
        return new AttendanceResource($attendance->load(['employee', 'shift']));
    }

    public function update(UpdateAttendanceRequest $request, Attendance $attendance): AttendanceResource
    {
        return new AttendanceResource($this->service->update($attendance, $request->validatedData()));
    }

    public function destroy(Attendance $attendance): JsonResponse
    {
        $this->service->delete($attendance);
        return response()->json(null, 204);
    }

    public function import(ImportAttendanceRequest $request): JsonResponse
    {
        $result = $this->importer->import($request->file('file'));
        return response()->json(['data' => $result]);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Requests\ImportAttendanceRequest;
use App\Modules\Attendance\Requests\StoreAttendanceRequest;
use App\Modules\Attendance\Requests\UpdateAttendanceRequest;
use App\Modules\Attendance\Resources\AttendanceResource;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Attendance\Services\DTRImportService;
use App\Modules\HR\Models\Employee;
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
        return AttendanceResource::collection($this->service->list($request->query(), $request->user()));
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'statuses' => array_map(static fn (AttendanceStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ], AttendanceStatus::cases()),
        ]]);
    }

    public function store(StoreAttendanceRequest $request): JsonResponse
    {
        $a = $this->service->create($request->validatedData());

        return (new AttendanceResource($a))->response()->setStatusCode(201);
    }

    public function show(Attendance $attendance, Request $request): AttendanceResource
    {
        $this->authorizeView($attendance->employee_id, $request);

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

    public function restore(Attendance $attendance): JsonResponse
    {
        $attendance->restore();
        return response()->json(['message' => 'Attendance restored.']);
    }

    public function import(ImportAttendanceRequest $request): JsonResponse
    {
        $result = $this->importer->import($request->file('file'));

        return response()->json(['data' => $result]);
    }

    private function authorizeView(int $employeeId, Request $request): void
    {
        $user = $request->user();
        if ($user?->role?->slug === 'system_admin'
            || $user?->hasPermission('attendance.edit')
            || $user?->hasPermission('attendance.import')) {
            return;
        }

        if ((int) $user?->employee_id === $employeeId) {
            return;
        }

        if ($user?->hasPermission('attendance.ot.approve') && $user->employee_id) {
            $ownDepartment = Employee::query()
                ->whereKey($user->employee_id)
                ->value('department_id');
            $recordDepartment = Employee::query()
                ->whereKey($employeeId)
                ->value('department_id');
            if ($ownDepartment && (int) $ownDepartment === (int) $recordDepartment) {
                return;
            }
        }

        abort(403, 'You do not have permission to view this attendance record.');
    }
}

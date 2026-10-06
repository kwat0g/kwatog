<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Modules\Attendance\Requests\PunchRequest;
use App\Modules\Attendance\Services\PunchService;
use App\Modules\HR\Models\Employee;
use Illuminate\Http\JsonResponse;

class PunchController
{
    public function __construct(private readonly PunchService $service) {}

    public function status(PunchRequest $request): JsonResponse
    {
        $employee = Employee::query()->findOrFail($request->user()->employee_id);

        return response()->json(['data' => $this->service->status($employee)]);
    }

    public function store(PunchRequest $request): JsonResponse
    {
        $employee = Employee::query()->findOrFail($request->user()->employee_id);

        $punch = $this->service->punch(
            $employee,
            (float) $request->input('lat'),
            (float) $request->input('lng'),
            (float) $request->input('accuracy'),
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json(['data' => [
            'direction' => $punch->direction->value,
            'punched_at' => $punch->punched_at->toIso8601String(),
            'distance_m' => (string) $punch->distance_m,
            'status' => $this->service->status($employee),
        ]], 201);
    }
}

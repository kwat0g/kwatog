<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Modules\Quality\Models\CalibrationRecord;
use App\Modules\Quality\Enums\CalibrationStatus;
use App\Modules\Quality\Requests\RecordCalibrationRequest;
use App\Modules\Quality\Requests\StoreCalibrationRecordRequest;
use App\Modules\Quality\Resources\CalibrationRecordResource;
use App\Modules\Quality\Services\CalibrationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class CalibrationController
{
    public function __construct(private readonly CalibrationService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(CalibrationStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = CalibrationRecord::query()->orderBy('next_calibration_date');

        if ($status = $validated['status'] ?? null) {
            $query->where('status', $status);
        }

        return CalibrationRecordResource::collection($query->paginate((int) ($validated['per_page'] ?? 25)));
    }

    public function store(StoreCalibrationRecordRequest $request): CalibrationRecordResource
    {
        return new CalibrationRecordResource($this->service->create($request->validated()));
    }

    public function show(CalibrationRecord $calibrationRecord): CalibrationRecordResource
    {
        return new CalibrationRecordResource($calibrationRecord);
    }

    public function update(StoreCalibrationRecordRequest $request, CalibrationRecord $calibrationRecord): CalibrationRecordResource
    {
        return new CalibrationRecordResource($this->service->update($calibrationRecord, $request->validated()));
    }

    public function recordCalibration(RecordCalibrationRequest $request, CalibrationRecord $calibrationRecord): CalibrationRecordResource
    {
        return new CalibrationRecordResource($this->service->recordCalibration($calibrationRecord, $request->validated('date')));
    }
}

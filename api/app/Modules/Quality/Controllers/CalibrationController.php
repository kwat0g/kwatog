<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Modules\Quality\Models\CalibrationRecord;
use App\Modules\Quality\Requests\StoreCalibrationRecordRequest;
use App\Modules\Quality\Resources\CalibrationRecordResource;
use App\Modules\Quality\Services\CalibrationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CalibrationController
{
    public function __construct(private readonly CalibrationService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = CalibrationRecord::query()->orderBy('next_calibration_date');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return CalibrationRecordResource::collection($query->paginate((int) $request->query('per_page', 25)));
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

    public function recordCalibration(Request $request, CalibrationRecord $calibrationRecord): CalibrationRecordResource
    {
        $date = $request->validate(['date' => ['required', 'date']])['date'];

        return new CalibrationRecordResource($this->service->recordCalibration($calibrationRecord, $date));
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Modules\Attendance\Models\Holiday;
use App\Modules\Attendance\Requests\StoreHolidayRequest;
use App\Modules\Attendance\Requests\UpdateHolidayRequest;
use App\Modules\Attendance\Resources\HolidayResource;
use App\Modules\Attendance\Services\HolidayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HolidayController
{
    public function __construct(private readonly HolidayService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return HolidayResource::collection($this->service->list($request->query()));
    }

    public function store(StoreHolidayRequest $request): JsonResponse
    {
        $h = $this->service->create($request->validated());
        return (new HolidayResource($h))->response()->setStatusCode(201);
    }

    public function show(Holiday $holiday): HolidayResource
    {
        return new HolidayResource($holiday);
    }

    public function update(UpdateHolidayRequest $request, Holiday $holiday): HolidayResource
    {
        return new HolidayResource($this->service->update($holiday, $request->validated()));
    }

    public function destroy(Holiday $holiday): JsonResponse
    {
        $this->service->delete($holiday);
        return response()->json(null, 204);
    }
}

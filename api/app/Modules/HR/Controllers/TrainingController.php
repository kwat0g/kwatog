<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Models\Training;
use App\Modules\HR\Requests\StoreTrainingRequest;
use App\Modules\HR\Requests\UpdateTrainingRequest;
use App\Modules\HR\Resources\TrainingResource;
use App\Modules\HR\Services\TrainingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TrainingController
{
    public function __construct(private readonly TrainingService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return TrainingResource::collection($this->service->list($request->query()));
    }

    public function store(StoreTrainingRequest $request): TrainingResource
    {
        return new TrainingResource($this->service->create($request->validated()));
    }

    public function show(Training $training): TrainingResource
    {
        return new TrainingResource($training->load('department'));
    }

    public function update(UpdateTrainingRequest $request, Training $training): TrainingResource
    {
        return new TrainingResource($this->service->update($training, $request->validated()));
    }

    public function destroy(Training $training): JsonResponse
    {
        $this->service->delete($training);
        return response()->json(null, 204);
    }
}

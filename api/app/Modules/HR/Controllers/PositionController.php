<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Models\Position;
use App\Modules\HR\Requests\StorePositionRequest;
use App\Modules\HR\Requests\UpdatePositionRequest;
use App\Modules\HR\Resources\PositionResource;
use App\Modules\HR\Services\PositionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PositionController
{
    public function __construct(private readonly PositionService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PositionResource::collection($this->service->list($request->query()));
    }

    public function store(StorePositionRequest $request): JsonResponse
    {
        $position = $this->service->create($request->validatedData());
        return (new PositionResource($position))->response()->setStatusCode(201);
    }

    public function show(Position $position): PositionResource
    {
        return new PositionResource($position->load('department')->loadCount('employees'));
    }

    public function update(UpdatePositionRequest $request, Position $position): PositionResource
    {
        return new PositionResource($this->service->update($position, $request->validatedData()));
    }

    public function destroy(Position $position): JsonResponse
    {
        try {
            $this->service->delete($position);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(null, 204);
    }
}

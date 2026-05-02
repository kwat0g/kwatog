<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Requests\StoreMaterialIssueRequest;
use App\Modules\Inventory\Resources\MaterialIssueSlipResource;
use App\Modules\Inventory\Services\MaterialIssueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

class MaterialIssueSlipController
{
    public function __construct(private readonly MaterialIssueService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return MaterialIssueSlipResource::collection($this->service->list($request->query()));
    }

    public function show(MaterialIssueSlip $materialIssueSlip): MaterialIssueSlipResource
    {
        return new MaterialIssueSlipResource($this->service->show($materialIssueSlip));
    }

    public function store(StoreMaterialIssueRequest $request): JsonResponse
    {
        try {
            $slip = $this->service->create($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return (new MaterialIssueSlipResource($slip))->response()->setStatusCode(201);
    }
}

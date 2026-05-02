<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Controllers;

use App\Modules\Purchasing\Models\ApprovedSupplier;
use App\Modules\Purchasing\Requests\StoreApprovedSupplierRequest;
use App\Modules\Purchasing\Requests\UpdateApprovedSupplierRequest;
use App\Modules\Purchasing\Resources\ApprovedSupplierResource;
use App\Modules\Purchasing\Services\ApprovedSupplierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ApprovedSupplierController
{
    public function __construct(private readonly ApprovedSupplierService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ApprovedSupplierResource::collection($this->service->list($request->query()));
    }

    public function store(StoreApprovedSupplierRequest $request): JsonResponse
    {
        $row = $this->service->create($request->validated());
        return (new ApprovedSupplierResource($row->load(['item', 'vendor'])))->response()->setStatusCode(201);
    }

    public function update(UpdateApprovedSupplierRequest $request, ApprovedSupplier $approvedSupplier): ApprovedSupplierResource
    {
        $row = $this->service->update($approvedSupplier, $request->validated());
        return new ApprovedSupplierResource($row->load(['item', 'vendor']));
    }

    public function destroy(ApprovedSupplier $approvedSupplier): JsonResponse
    {
        $this->service->delete($approvedSupplier);
        return response()->json(null, 204);
    }
}

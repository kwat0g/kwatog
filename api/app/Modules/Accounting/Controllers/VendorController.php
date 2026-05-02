<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Requests\StoreVendorRequest;
use App\Modules\Accounting\Requests\UpdateVendorRequest;
use App\Modules\Accounting\Resources\VendorResource;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Accounting\Services\VendorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VendorController
{
    public function __construct(
        private readonly VendorService $service,
        private readonly BillService $bills,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return VendorResource::collection($this->service->list($request->query()));
    }

    public function show(Vendor $vendor): VendorResource
    {
        $vendor = $this->service->show($vendor);
        $vendor->setAttribute('open_balance', $this->bills->openBalance($vendor));
        return new VendorResource($vendor);
    }

    public function store(StoreVendorRequest $request): JsonResponse
    {
        $vendor = $this->service->create($request->validated());
        return (new VendorResource($vendor))->response()->setStatusCode(201);
    }

    public function update(UpdateVendorRequest $request, Vendor $vendor): VendorResource
    {
        $vendor = $this->service->update($vendor, $request->validated());
        return new VendorResource($vendor);
    }

    public function destroy(Vendor $vendor): JsonResponse
    {
        try {
            $this->service->delete($vendor);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(null, 204);
    }
}

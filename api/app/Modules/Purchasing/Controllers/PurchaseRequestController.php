<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Controllers;

use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Requests\ConvertPrToPoRequest;
use App\Modules\Purchasing\Requests\RejectPurchaseRequestRequest;
use App\Modules\Purchasing\Requests\StorePurchaseRequestRequest;
use App\Modules\Purchasing\Requests\UpdatePurchaseRequestRequest;
use App\Modules\Purchasing\Resources\PurchaseOrderResource;
use App\Modules\Purchasing\Resources\PurchaseRequestResource;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

class PurchaseRequestController
{
    public function __construct(
        private readonly PurchaseRequestService $service,
        private readonly PurchaseOrderService $poService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PurchaseRequestResource::collection($this->service->list($request->query()));
    }

    public function show(PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        return new PurchaseRequestResource($this->service->show($purchaseRequest));
    }

    public function store(StorePurchaseRequestRequest $request): JsonResponse
    {
        $pr = $this->service->create($request->validated(), $request->user());
        return (new PurchaseRequestResource($pr))->response()->setStatusCode(201);
    }

    public function update(UpdatePurchaseRequestRequest $request, PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        try {
            $pr = $this->service->update($purchaseRequest, $request->validated());
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        return new PurchaseRequestResource($pr);
    }

    public function destroy(PurchaseRequest $purchaseRequest): JsonResponse
    {
        try { $this->service->delete($purchaseRequest); }
        catch (RuntimeException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        return response()->json(null, 204);
    }

    public function submit(PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        try { $pr = $this->service->submit($purchaseRequest); }
        catch (RuntimeException $e) { abort(422, $e->getMessage()); }
        return new PurchaseRequestResource($this->service->show($pr));
    }

    public function approve(Request $request, PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        try { $pr = $this->service->approve($purchaseRequest, $request->user(), $request->input('remarks')); }
        catch (RuntimeException $e) { abort(422, $e->getMessage()); }
        return new PurchaseRequestResource($this->service->show($pr));
    }

    public function reject(RejectPurchaseRequestRequest $request, PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        try { $pr = $this->service->reject($purchaseRequest, $request->user(), $request->validated()['reason']); }
        catch (RuntimeException $e) { abort(422, $e->getMessage()); }
        return new PurchaseRequestResource($this->service->show($pr));
    }

    public function cancel(PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        try { $pr = $this->service->cancel($purchaseRequest); }
        catch (RuntimeException $e) { abort(422, $e->getMessage()); }
        return new PurchaseRequestResource($this->service->show($pr));
    }

    public function convert(ConvertPrToPoRequest $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        try {
            $pos = $this->poService->convertFromPr($purchaseRequest, $request->validated()['vendor_map'], $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json([
            'data' => PurchaseOrderResource::collection(collect($pos))->resolve(),
        ], 201);
    }
}

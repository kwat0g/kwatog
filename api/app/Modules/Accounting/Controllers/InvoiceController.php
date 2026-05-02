<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Requests\StoreCollectionRequest;
use App\Modules\Accounting\Requests\StoreInvoiceRequest;
use App\Modules\Accounting\Resources\CollectionResource;
use App\Modules\Accounting\Resources\InvoiceResource;
use App\Modules\Accounting\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InvoiceController
{
    public function __construct(private readonly InvoiceService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return InvoiceResource::collection($this->service->list($request->query()));
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        return new InvoiceResource($this->service->show($invoice));
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        try {
            $inv = $this->service->create($request->validated(), $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return (new InvoiceResource($inv))->response()->setStatusCode(201);
    }

    public function update(StoreInvoiceRequest $request, Invoice $invoice): InvoiceResource|JsonResponse
    {
        try {
            $inv = $this->service->update($invoice, $request->validated(), $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new InvoiceResource($inv);
    }

    public function finalize(Request $request, Invoice $invoice): InvoiceResource|JsonResponse
    {
        try {
            $inv = $this->service->finalize($invoice, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new InvoiceResource($inv);
    }

    public function cancel(Request $request, Invoice $invoice): InvoiceResource|JsonResponse
    {
        try {
            $inv = $this->service->cancel($invoice, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new InvoiceResource($inv);
    }

    public function recordCollection(StoreCollectionRequest $request, Invoice $invoice): JsonResponse
    {
        try {
            $coll = $this->service->recordCollection($invoice, $request->validated(), $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return (new CollectionResource($coll))->response()->setStatusCode(201);
    }
}

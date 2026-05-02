<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Requests\CancelSalesOrderRequest;
use App\Modules\CRM\Requests\StoreSalesOrderRequest;
use App\Modules\CRM\Requests\UpdateSalesOrderRequest;
use App\Modules\CRM\Resources\SalesOrderResource;
use App\Modules\CRM\Services\SalesOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

class SalesOrderController
{
    public function __construct(private readonly SalesOrderService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return SalesOrderResource::collection($this->service->list($request->query()));
    }

    public function show(SalesOrder $salesOrder): SalesOrderResource
    {
        return new SalesOrderResource($this->service->show($salesOrder));
    }

    public function store(StoreSalesOrderRequest $request): JsonResponse
    {
        $so = $this->service->create($request->validated(), $request->user()->id);
        return (new SalesOrderResource($so))->response()->setStatusCode(201);
    }

    public function update(UpdateSalesOrderRequest $request, SalesOrder $salesOrder): SalesOrderResource|JsonResponse
    {
        try {
            $so = $this->service->update($salesOrder, $request->validated());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new SalesOrderResource($so);
    }

    public function destroy(SalesOrder $salesOrder): JsonResponse
    {
        try {
            $this->service->delete($salesOrder);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(null, 204);
    }

    public function confirm(SalesOrder $salesOrder): SalesOrderResource|JsonResponse
    {
        if (! $this->user()->hasPermission('crm.sales_orders.confirm')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        try {
            $so = $this->service->confirm($salesOrder);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new SalesOrderResource($so);
    }

    public function cancel(CancelSalesOrderRequest $request, SalesOrder $salesOrder): SalesOrderResource|JsonResponse
    {
        try {
            $so = $this->service->cancel($salesOrder, $request->input('reason'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new SalesOrderResource($so);
    }

    public function chain(SalesOrder $salesOrder): JsonResponse
    {
        return response()->json(['data' => $this->service->chain($salesOrder)]);
    }

    private function user()
    {
        return request()->user();
    }
}

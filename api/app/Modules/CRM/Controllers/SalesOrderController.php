<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Exceptions\SalesOrderStateConflictException;
use App\Modules\CRM\Requests\CancelSalesOrderRequest;
use App\Modules\CRM\Requests\ListSalesOrderRequest;
use App\Modules\CRM\Requests\StoreSalesOrderRequest;
use App\Modules\CRM\Requests\UpdateSalesOrderRequest;
use App\Modules\CRM\Resources\SalesOrderResource;
use App\Modules\CRM\Services\SalesOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use App\Common\Exceptions\BusinessRuleException;

class SalesOrderController
{
    public function __construct(private readonly SalesOrderService $service) {}

    public function index(ListSalesOrderRequest $request): AnonymousResourceCollection
    {
        return SalesOrderResource::collection($this->service->list($request->validated()));
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'statuses' => array_map(static fn (SalesOrderStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
                'next_statuses' => array_map(
                    static fn (string $next): array => ['value' => $next, 'label' => SalesOrderStatus::tryFrom($next)?->label() ?? $next],
                    SalesOrderService::allowedTransitions()[$status->value] ?? [],
                ),
            ], SalesOrderStatus::cases()),
        ]]);
    }

    public function show(SalesOrder $salesOrder): SalesOrderResource
    {
        $order = $this->service->show($salesOrder);
        $order->loadMissing('customerFeedback.portalUser:id,name');

        return new SalesOrderResource($order);
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
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new SalesOrderResource($so);
    }

    public function destroy(SalesOrder $salesOrder): JsonResponse
    {
        try {
            $this->service->delete($salesOrder);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(null, 204);
    }

    public function restore(SalesOrder $salesOrder): SalesOrderResource|JsonResponse
    {
        try {
            $so = $this->service->restore($salesOrder);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new SalesOrderResource($so);
    }

    public function confirm(SalesOrder $salesOrder): SalesOrderResource|JsonResponse
    {
        if (! $this->user()->hasPermission('crm.sales_orders.confirm')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        // General business rules still reach the render hook in bootstrap/app.php,
        // preserving their structured `errors` bag and `code`. Only the locked
        // stale-state conflict is handled here because it requires HTTP 409.
        // ChainErrorPanel uses `errors.bom` / `code === 'missing_bom'` to offer
        // "Manage BOMs" for MissingBomException and BomStructureException. A
        // message-only controller response would hide that recovery action.
        //
        // NoPriceAgreementException also passes through now: it is an
        // HttpResponseException carrying its own 422 with errors.product_id.
        try {
            $result = $this->service->confirmWithChainResult($salesOrder);
        } catch (SalesOrderStateConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [$e->errorKey() => [$e->getMessage()]],
                'code' => $e->errorCode(),
            ], 409);
        }

        return response()->json([
            'data' => (new SalesOrderResource($result['so']))->resolve(),
            'chain_result' => $result['chain_result'],
        ]);
    }

    public function requestCustomerConfirmation(SalesOrder $salesOrder): SalesOrderResource
    {
        return new SalesOrderResource($this->service->requestCustomerConfirmation($salesOrder));
    }

    public function cancel(CancelSalesOrderRequest $request, SalesOrder $salesOrder): SalesOrderResource|JsonResponse
    {
        try {
            $so = $this->service->cancel($salesOrder, $request->input('reason'), null, $request->user());
        } catch (BusinessRuleException $e) {
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

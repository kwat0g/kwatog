<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Requests\CancelSalesOrderRequest;
use App\Modules\CRM\Requests\StoreSalesOrderRequest;
use App\Modules\CRM\Requests\UpdateSalesOrderRequest;
use App\Modules\CRM\Resources\SalesOrderResource;
use App\Modules\CRM\Services\SalesOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use App\Common\Exceptions\BusinessRuleException;

class SalesOrderController
{
    public function __construct(private readonly SalesOrderService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return SalesOrderResource::collection($this->service->list($request->query()));
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

    public function restore(SalesOrder $salesOrder): JsonResponse
    {
        $salesOrder->restore();
        return response()->json(['message' => 'Sales order restored.']);
    }

    public function confirm(SalesOrder $salesOrder): SalesOrderResource|JsonResponse
    {
        if (! $this->user()->hasPermission('crm.sales_orders.confirm')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        // Deliberately uncaught. The render hook in bootstrap/app.php turns a
        // BusinessRuleException into the same 422 this arm produced, plus the
        // `errors` bag and `code` the subclasses carry — and re-emitting only
        // getMessage() here threw both away. ChainErrorPanel decides whether to
        // offer "Manage BOMs" from `errors.bom` / `code === 'missing_bom'`
        // (MissingBomException, BomStructureException), so with the arm in place
        // the button that fixes the failure could never appear. This is the one
        // endpoint in this sweep whose client reads the structured fields; the
        // rest keep their arm because `applyServerValidationErrors` replaces a
        // message with a generic "The server flagged some fields" as soon as an
        // `errors` bag is present.
        //
        // NoPriceAgreementException also passes through now: it is an
        // HttpResponseException carrying its own 422 with errors.product_id, and
        // HttpResponseException::getMessage() is empty, so this arm used to
        // answer a missing price agreement with `422 {"message":""}`.
        $result = $this->service->confirmWithChainResult($salesOrder);

        return response()->json([
            'data' => (new SalesOrderResource($result['so']))->resolve(),
            'chain_result' => $result['chain_result'],
        ]);
    }

    public function cancel(CancelSalesOrderRequest $request, SalesOrder $salesOrder): SalesOrderResource|JsonResponse
    {
        try {
            $so = $this->service->cancel($salesOrder, $request->input('reason'));
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new SalesOrderResource($so);
    }

    public function transition(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        $target = SalesOrderStatus::tryFrom((string) $request->input('status'));
        if ($target === null) return response()->json(['message' => 'Unknown sales order status.'], 422);
        $result = $this->service->transitionTo($salesOrder->id, $target, $request->user()?->id);
        return response()->json(['data' => $result->toArray()], $result->statusCode);
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

<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\HashIdFilter;
use App\Modules\Accounting\Exceptions\ClosedPeriodException;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\InvalidMovementException;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Requests\StoreMaterialIssueRequest;
use App\Modules\Inventory\Resources\MaterialIssueSlipResource;
use App\Modules\Inventory\Services\MaterialIssueService;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

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

    /** Inventory-owned operational lookups for Warehouse material issue entry. */
    public function options(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'work_order_id' => ['nullable', 'string'],
            'item_id' => ['nullable', 'string'],
        ]);
        $workOrderId = $this->decodeOptionId($filters['work_order_id'] ?? null, 'work_order_id', WorkOrder::class);
        $itemId = $this->decodeOptionId($filters['item_id'] ?? null, 'item_id', Item::class);

        return response()->json(['data' => $this->service->options($workOrderId, $itemId)]);
    }

    private function decodeOptionId(?string $value, string $field, string $model): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = HashIdFilter::decode($value, $model);
        if ($id === null) {
            throw ValidationException::withMessages([$field => ['The selected value is invalid.']]);
        }

        return $id;
    }

    public function store(StoreMaterialIssueRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['idempotency_key'] = $request->idempotencyKey();
            $slip = $this->service->create($data, $request->user());
        } catch (BusinessRuleException|ClosedPeriodException|InsufficientStockException|InvalidMovementException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return (new MaterialIssueSlipResource($slip))->response()->setStatusCode(201);
    }

    public function cancel(MaterialIssueSlip $materialIssueSlip, Request $request): MaterialIssueSlipResource
    {
        $this->service->cancel($materialIssueSlip, $request->user());
        return new MaterialIssueSlipResource($this->service->show($materialIssueSlip));
    }
}

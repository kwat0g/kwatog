<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\HashIdFilter;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Exceptions\MaterialReturnConflictException;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Requests\StoreMaterialReturnRequest;
use App\Modules\Inventory\Resources\StockMovementResource;
use App\Modules\Inventory\Services\MaterialReturnService;
use App\Modules\Inventory\Services\MovementGlPostingService;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class StockMovementController
{
    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'movement_types' => array_map(static fn (StockMovementType $type): array => ['value' => $type->value, 'label' => str_replace('_', ' ', ucfirst($type->value))], StockMovementType::cases()),
        ]]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['per_page' => 'sometimes|integer|min:1|max:200', 'from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])], 'search' => 'nullable|string|max:200', 'item_id' => 'nullable|string', 'movement_id' => 'nullable|string', 'work_order_id' => 'nullable|string', 'reference_type' => 'nullable|string|max:100', 'movement_type' => 'nullable|string|max:100']);
        $q = StockMovement::query()->withHandoverBalance()->with([
            'item', 'referenceWorkOrder', 'referenceOutput.workOrder', 'referenceSlip.workOrder',
            'referenceGoodsReceiptNote', 'referenceDeliveryItem.delivery',
            'referenceSource.referenceWorkOrder', 'referenceSource.referenceSlip.workOrder', 'handover.recorder',
            'fromLocation',
            'toLocation',
            'creator:id,name,role_id',
            'journalEntry:id,entry_number',
        ]);
        if ($request->filled('item_id')) {
            $iid = HashIdFilter::decode($request->input('item_id'), Item::class);
            if (! $iid) {
                throw ValidationException::withMessages(['item_id' => 'Select a valid item.']);
            }
            $q->where('item_id', $iid);
        }
        if ($request->filled('movement_type')) {
            $q->where('movement_type', $request->input('movement_type'));
        }
        if ($request->filled('from')) {
            $q->where('created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $q->where('created_at', '<=', $request->input('to').' 23:59:59');
        }
        if ($request->filled('reference_type')) {
            $q->where('reference_type', $request->input('reference_type'));
        }
        if ($request->filled('movement_id')) {
            $movementId = HashIdFilter::decode($request->input('movement_id'), StockMovement::class);
            if (! $movementId) {
                throw ValidationException::withMessages(['movement_id' => 'Select a valid movement.']);
            }
            $q->whereKey($movementId);
        }

        if ($request->filled('work_order_id')) {
            $woId = HashIdFilter::decode($request->input('work_order_id'), WorkOrder::class);
            if (! $woId) {
                throw ValidationException::withMessages(['work_order_id' => 'Select a valid work order.']);
            }
            $q->forWorkOrder($woId);
        }
        if ($search = trim((string) $request->input('search'))) {
            $q->where(fn ($query) => $query
                ->whereHas('item', fn ($item) => $item->where('code', 'ilike', '%'.$search.'%')->orWhere('name', 'ilike', '%'.$search.'%'))
                ->orWhere('remarks', 'ilike', '%'.$search.'%')->orWhere('lot_number', 'ilike', '%'.$search.'%')
                ->orWhere(fn ($wo) => $wo->whereIn('reference_type', ['work_order', 'WorkOrder'])
                    ->whereHas('referenceWorkOrder', fn ($record) => $record->where('wo_number', 'ilike', '%'.$search.'%')))
                ->orWhere(fn ($output) => $output->whereIn('reference_type', ['work_order_output', 'WorkOrderOutput'])
                    ->whereHas('referenceOutput.workOrder', fn ($record) => $record->where('wo_number', 'ilike', '%'.$search.'%')))
                ->orWhere(fn ($slip) => $slip->where('reference_type', 'material_issue_slip')
                    ->whereHas('referenceSlip', fn ($record) => $record->where('slip_number', 'ilike', '%'.$search.'%')
                        ->orWhereHas('workOrder', fn ($wo) => $wo->where('wo_number', 'ilike', '%'.$search.'%'))))
                ->orWhere(fn ($return) => $return->where('reference_type', 'stock_movement')
                    ->where('movement_type', StockMovementType::MaterialReturn->value)
                    ->whereHas('referenceSource', fn ($source) => $source->where(fn ($issue) => $issue
                        ->where(fn ($direct) => $direct->whereIn('reference_type', ['work_order', 'WorkOrder'])
                            ->whereHas('referenceWorkOrder', fn ($wo) => $wo->where('wo_number', 'ilike', '%'.$search.'%')))
                        ->orWhere(fn ($slip) => $slip->where('reference_type', 'material_issue_slip')
                            ->whereHas('referenceSlip', fn ($record) => $record->where('slip_number', 'ilike', '%'.$search.'%')
                                ->orWhereHas('workOrder', fn ($wo) => $wo->where('wo_number', 'ilike', '%'.$search.'%'))))))));
        }

        return StockMovementResource::collection(
            $q->orderByDesc('created_at')->orderByDesc('id')->paginate(min((int) $request->input('per_page', 50), 200))
        );
    }

    public function retryGlHandoff(
        StockMovement $stockMovement,
        MovementGlPostingService $postings,
    ): StockMovementResource|JsonResponse {
        try {
            $movement = $postings->retry($stockMovement);
        } catch (BusinessRuleException) {
            return response()->json(['message' => 'The stock movement could not be posted to the General Ledger.'], 422);
        }

        return new StockMovementResource($movement->load([
            'item', 'fromLocation', 'toLocation', 'creator:id,name,role_id', 'journalEntry:id,entry_number',
        ]));
    }

    public function returnOptions(StockMovement $stockMovement, MaterialReturnService $returns): JsonResponse
    {
        return response()->json(['data' => $returns->options($stockMovement)]);
    }

    public function returnUnused(
        StockMovement $stockMovement,
        StoreMaterialReturnRequest $request,
        MaterialReturnService $returns,
    ): StockMovementResource|JsonResponse {
        try {
            $data = $request->validated();
            $data['idempotency_key'] = $request->idempotencyKey();
            $movement = $returns->returnUnused($stockMovement, $data, $request->user());
        } catch (MaterialReturnConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'material_return_conflict',
            ], 409);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new StockMovementResource($movement->load([
            'item', 'fromLocation', 'toLocation', 'creator:id,name,role_id', 'journalEntry:id,entry_number',
        ]));
    }
}

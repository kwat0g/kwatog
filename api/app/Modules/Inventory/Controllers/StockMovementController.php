<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\HashIdFilter;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Resources\StockMovementResource;
use App\Modules\Inventory\Services\MovementGlPostingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
        $q = StockMovement::query()->with([
            'item',
            'fromLocation',
            'toLocation',
            'creator:id,name,role_id',
            'journalEntry:id,entry_number',
        ]);
        if ($request->filled('item_id')) {
            $iid = HashIdFilter::decode($request->input('item_id'), Item::class);
            if ($iid) $q->where('item_id', $iid);
        }
        if ($request->filled('movement_type')) $q->where('movement_type', $request->input('movement_type'));
        if ($request->filled('from')) $q->where('created_at', '>=', $request->input('from'));
        if ($request->filled('to'))   $q->where('created_at', '<=', $request->input('to').' 23:59:59');
        if ($request->filled('reference_type')) $q->where('reference_type', $request->input('reference_type'));
        if ($request->filled('movement_id')) {
            $movementId = HashIdFilter::decode($request->input('movement_id'), StockMovement::class);
            if ($movementId) $q->whereKey($movementId);
        }

        return StockMovementResource::collection(
            $q->orderByDesc('created_at')->paginate(min((int) $request->input('per_page', 50), 200))
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
}

<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Common\Services\OutboxService;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemUomConversion;
use App\Modules\Inventory\Models\MaterialIssueSlipItem;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Requests\StoreItemUomConversionRequest;
use App\Modules\Inventory\Requests\StoreUomRequest;
use App\Modules\Inventory\Resources\ItemUomConversionResource;
use App\Modules\Inventory\Resources\UomResource;
use App\Modules\MRP\Events\MrpReplanRequested;
use App\Modules\MRP\Services\BomCostingService;
use App\Modules\MRP\Services\MrpScopeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * OGAMI-004 — UOM catalog CRUD + per-item conversion management.
 */
class UomController
{
    /* ─── UOM catalog ─── */

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Uom::query()->orderBy('code');
        if ($request->query('trashed') === 'only') {
            $query->onlyTrashed();
        } elseif ($request->query('trashed') === 'with') {
            $query->withTrashed();
        }

        return UomResource::collection($query->get());
    }

    public function store(StoreUomRequest $request): JsonResponse
    {
        $uom = Uom::create($request->validated());

        return (new UomResource($uom))->response()->setStatusCode(201);
    }

    public function update(StoreUomRequest $request, Uom $uom): UomResource|JsonResponse
    {
        $data = $request->validated();

        return DB::transaction(function () use ($uom, $data): UomResource|JsonResponse {
            $locked = Uom::query()->lockForUpdate()->findOrFail($uom->id);
            if ($data['code'] !== $locked->code && $this->isReferenced($locked)) {
                return response()->json(
                    ['message' => 'Cannot change a UOM code while items, products, or conversions reference it.'],
                    422,
                );
            }

            $locked->update($data);

            return new UomResource($locked);
        });
    }

    public function destroy(Uom $uom): JsonResponse
    {
        return DB::transaction(function () use ($uom): JsonResponse {
            $locked = Uom::query()->lockForUpdate()->findOrFail($uom->id);
            if ($this->isReferenced($locked)) {
                return response()->json(
                    ['message' => 'Cannot delete a UOM that is referenced by items, products, or conversions.'],
                    422,
                );
            }

            $locked->delete();

            return response()->json(null, 204);
        });
    }

    public function restore(Uom $uom): JsonResponse
    {
        $uom->restore();

        return response()->json(['message' => 'UOM restored.']);
    }

    /* ─── Per-item conversions ─── */

    public function conversions(Item $item): AnonymousResourceCollection
    {
        $rows = $item->uomConversions()->with(['fromUom', 'toUom'])->get();
        $rows->each(fn (ItemUomConversion $conversion) => $conversion->setAttribute(
            'locked_by_history',
            $this->conversionHasHistory($conversion),
        ));

        return ItemUomConversionResource::collection($rows);
    }

    public function storeConversion(StoreItemUomConversionRequest $request, Item $item): JsonResponse
    {
        $data = $request->validated();
        $fromId = (int) $data['from_uom_id'];
        $toId = (int) $data['to_uom_id'];

        return DB::transaction(function () use ($item, $fromId, $toId, $data): JsonResponse {
            $lockedItem = Item::query()->lockForUpdate()->findOrFail($item->id);
            $uomIds = collect([$fromId, $toId])->unique()->sort()->values();
            $uoms = Uom::query()
                ->whereIn('id', $uomIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($uoms->count() !== $uomIds->count()) {
                return response()->json(['message' => 'The selected units of measure are no longer available.'], 422);
            }

            $target = $uoms->firstWhere('id', $toId);
            if (strcasecmp((string) $target->code, (string) $lockedItem->unit_of_measure) !== 0) {
                return response()->json(['message' => 'Target unit must match the item base unit.'], 422);
            }
            $source = $uoms->firstWhere('id', $fromId);
            if (strcasecmp((string) $source->code, (string) $lockedItem->unit_of_measure) === 0) {
                return response()->json(['message' => 'Source unit must differ from the item base unit.'], 422);
            }

            $conv = ItemUomConversion::query()
                ->where('item_id', $lockedItem->id)
                ->where('from_uom_id', $fromId)
                ->where('to_uom_id', $toId)
                ->lockForUpdate()
                ->first();
            if ($conv && $this->conversionHasHistory($conv)) {
                return response()->json(
                    ['message' => 'Cannot change or remove a UOM conversion while a purchasing document, receipt, or material issue uses it.'],
                    422,
                );
            }

            $conv ??= new ItemUomConversion([
                'item_id' => $lockedItem->id,
                'from_uom_id' => $fromId,
                'to_uom_id' => $toId,
            ]);
            $conv->factor = $data['factor'];
            $conv->save();
            $this->invalidateConversionCosts((int) $lockedItem->id);

            return (new ItemUomConversionResource($conv->load(['fromUom', 'toUom'])))
                ->response()->setStatusCode(201);
        }, 3);
    }

    public function destroyConversion(Item $item, ItemUomConversion $itemUomConversion): JsonResponse
    {
        if ($itemUomConversion->item_id !== $item->id) {
            return response()->json(['message' => 'Conversion does not belong to this item.'], 404);
        }

        return DB::transaction(function () use ($item, $itemUomConversion): JsonResponse {
            Item::query()->lockForUpdate()->findOrFail($item->id);
            $locked = ItemUomConversion::query()->lockForUpdate()->findOrFail($itemUomConversion->id);
            if ($this->conversionHasHistory($locked)) {
                return response()->json(
                    ['message' => 'Cannot change or remove a UOM conversion while a purchasing document, receipt, or material issue uses it.'],
                    422,
                );
            }

            $locked->delete();
            $this->invalidateConversionCosts((int) $item->id);

            return response()->json(null, 204);
        }, 3);
    }

    private function isReferenced(Uom $uom): bool
    {
        $code = strtoupper($uom->code);

        return Item::withTrashed()->whereRaw('LOWER(unit_of_measure) = LOWER(?)', [$uom->code])->exists()
            || Product::withTrashed()->whereRaw('LOWER(unit_of_measure) = LOWER(?)', [$uom->code])->exists()
            || $uom->conversionsFrom()->exists()
            || $uom->conversionsTo()->exists()
            || GrnItem::query()->whereRaw('UPPER(received_uom_code) = ?', [$code])->exists()
            || MaterialIssueSlipItem::query()->whereRaw('UPPER(issued_uom_code) = ?', [$code])->exists();
    }

    private function invalidateConversionCosts(int $itemId): void
    {
        app(BomCostingService::class)->invalidateForItems([$itemId]);
        $salesOrderIds = app(MrpScopeResolver::class)->salesOrderIdsForItems([$itemId]);
        if ($salesOrderIds !== []) {
            app(OutboxService::class)->record(
                new MrpReplanRequested($salesOrderIds, 'uom_conversion_changed', auth()->id()),
                'mrp:replan:uom:'.$itemId.':'.Str::uuid(),
            );
        }
    }

    private function conversionHasHistory(ItemUomConversion $conversion): bool
    {
        $code = strtoupper((string) $conversion->fromUom()->value('code'));

        $retainedOrder = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_order_items.item_id', $conversion->item_id)
            ->whereRaw('UPPER(purchase_order_items.unit) = ?', [$code])
            ->exists();
        $openRequest = DB::table('purchase_request_items')
            ->join('purchase_requests', 'purchase_requests.id', '=', 'purchase_request_items.purchase_request_id')
            ->where('purchase_request_items.item_id', $conversion->item_id)
            ->whereRaw('UPPER(purchase_request_items.unit) = ?', [$code])
            ->whereNull('purchase_requests.deleted_at')
            ->whereIn('purchase_requests.status', ['draft', 'pending', 'approved'])->exists();

        return $retainedOrder || $openRequest || GrnItem::query()
            ->where('item_id', $conversion->item_id)
            ->whereRaw('UPPER(received_uom_code) = ?', [$code])
            ->exists()
            || MaterialIssueSlipItem::query()
                ->where('item_id', $conversion->item_id)
                ->whereRaw('UPPER(issued_uom_code) = ?', [$code])
                ->exists();
    }
}

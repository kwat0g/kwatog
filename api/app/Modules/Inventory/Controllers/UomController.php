<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Common\Support\HashIdFilter;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemUomConversion;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Requests\StoreItemUomConversionRequest;
use App\Modules\Inventory\Requests\StoreUomRequest;
use App\Modules\Inventory\Resources\ItemUomConversionResource;
use App\Modules\Inventory\Resources\UomResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * OGAMI-004 — UOM catalog CRUD + per-item conversion management.
 */
class UomController
{
    /* ─── UOM catalog ─── */

    public function index(): AnonymousResourceCollection
    {
        return UomResource::collection(Uom::query()->orderBy('code')->get());
    }

    public function store(StoreUomRequest $request): JsonResponse
    {
        $uom = Uom::create($request->validated());
        return (new UomResource($uom))->response()->setStatusCode(201);
    }

    public function update(StoreUomRequest $request, Uom $uom): UomResource
    {
        $uom->update($request->validated());
        return new UomResource($uom);
    }

    public function destroy(Uom $uom): JsonResponse
    {
        if ($uom->conversionsFrom()->exists() || $uom->conversionsTo()->exists()) {
            return response()->json(
                ['message' => 'Cannot delete a UOM that is referenced by item conversions.'],
                422,
            );
        }
        $uom->delete();
        return response()->json(null, 204);
    }

    /* ─── Per-item conversions ─── */

    public function conversions(Item $item): AnonymousResourceCollection
    {
        $rows = $item->uomConversions()->with(['fromUom', 'toUom'])->get();
        return ItemUomConversionResource::collection($rows);
    }

    public function storeConversion(StoreItemUomConversionRequest $request, Item $item): JsonResponse
    {
        $data = $request->validated();

        $fromId = HashIdFilter::decode($data['from_uom_id'], Uom::class) ?? (int) $data['from_uom_id'];
        $toId   = HashIdFilter::decode($data['to_uom_id'], Uom::class) ?? (int) $data['to_uom_id'];

        $conv = ItemUomConversion::updateOrCreate(
            ['item_id' => $item->id, 'from_uom_id' => $fromId, 'to_uom_id' => $toId],
            ['factor' => $data['factor']],
        );

        return (new ItemUomConversionResource($conv->load(['fromUom', 'toUom'])))
            ->response()->setStatusCode(201);
    }

    public function destroyConversion(Item $item, ItemUomConversion $itemUomConversion): JsonResponse
    {
        if ($itemUomConversion->item_id !== $item->id) {
            return response()->json(['message' => 'Conversion does not belong to this item.'], 404);
        }
        $itemUomConversion->delete();
        return response()->json(null, 204);
    }
}

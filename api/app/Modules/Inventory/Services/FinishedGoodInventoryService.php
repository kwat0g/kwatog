<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCategory;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\WarehouseLocation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinishedGoodInventoryService
{
    public function ensureItem(Product $product): Item
    {
        return DB::transaction(function () use ($product): Item {
            // Serialize automatic provisioning across different work orders for the same product.
            $product = Product::withTrashed()->lockForUpdate()->findOrFail($product->id);
            $item = Item::withTrashed()->whereRaw('UPPER(code) = ?', [strtoupper($product->part_number)])->lockForUpdate()->first();
            if ($item) {
                $this->assertMapping($product, $item);

                return $item;
            }
            $this->assertUnit($product, lock: true);
            $categories = ItemCategory::withTrashed()->orderBy('id')->lockForUpdate()->get();
            $category = $categories->first(fn ($category) => ! $category->trashed() && $category->item_type === ItemType::FinishedGood->value);
            $category ??= app(ItemCategoryService::class)->create(['name' => 'Finished Goods', 'item_type' => ItemType::FinishedGood->value]);
            try {
                return app(ItemService::class)->create([
                    'code' => $product->part_number, 'name' => $product->name,
                    'description' => $product->description, 'category_id' => $category->id,
                    'item_type' => ItemType::FinishedGood->value, 'unit_of_measure' => $product->unit_of_measure,
                    'standard_cost' => $product->standard_cost, 'is_active' => true,
                ]);
            } catch (UniqueConstraintViolationException) {
                // A manual item creation may win the unique-code race; validate before adopting it.
                $item = Item::withTrashed()->where('code', $product->part_number)->lockForUpdate()->firstOrFail();
                $this->assertMapping($product, $item);

                return $item;
            } catch (ValidationException $error) {
                throw new BusinessRuleException('Finished Goods setup invalid. Ask Warehouse to check the category and unit.');
            }
        });
    }

    public function assertReady(Product $product): void
    {
        $item = Item::withTrashed()->whereRaw('UPPER(code) = ?', [strtoupper($product->part_number)])->first();
        if ($item) {
            $this->assertMapping($product, $item);
        } else {
            $this->assertUnit($product);
        }
        $this->location();
    }

    public function location(): WarehouseLocation
    {
        $location = WarehouseLocation::query()->where('is_active', true)->where('is_blocked', false)
            ->whereHas('zone', fn ($zone) => $zone->where('zone_type', WarehouseZoneType::FinishedGoods->value)
                ->whereHas('warehouse', fn ($warehouse) => $warehouse->where('is_active', true)))
            ->orderBy('id')->first();
        if (! $location) {
            throw new BusinessRuleException('Finished Goods location unavailable. Ask Warehouse to add an active, unblocked bin.');
        }

        return $location;
    }

    private function assertMapping(Product $product, Item $item): void
    {
        if ($item->code !== $product->part_number || $item->trashed() || ! $item->is_active || $item->item_type !== ItemType::FinishedGood) {
            throw new BusinessRuleException('Finished Goods item unavailable or code conflicts. Ask Warehouse to check '.$product->part_number.'.');
        }
        if (strcasecmp(trim($item->unit_of_measure), trim($product->unit_of_measure)) !== 0) {
            throw new BusinessRuleException('Product and Finished Goods units differ. Ask Warehouse to correct the mapping.');
        }
    }

    private function assertUnit(Product $product, bool $lock = false): void
    {
        $unit = Uom::whereRaw('LOWER(code) = ?', [strtolower(trim($product->unit_of_measure))]);
        if ($lock) {
            $unit->lockForUpdate();
        }
        if (! $unit->first()) {
            throw new BusinessRuleException('Product unit missing. Ask Warehouse to configure '.$product->unit_of_measure.'.');
        }
    }
}

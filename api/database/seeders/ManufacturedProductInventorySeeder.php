<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCategory;
use App\Modules\Inventory\Models\Uom;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Creates missing inventory identities, without manufacturing stock or rewriting costs. */
class ManufacturedProductInventorySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $category = ItemCategory::firstOrCreate(['name' => 'Finished Goods'], ['item_type' => 'finished_good']);
            foreach (Product::active()->whereHas('activeBom')->orderBy('id')->lockForUpdate()->get() as $product) {
                $existing = Item::withTrashed()->where('code', $product->part_number)->first();
                if ($existing) {
                    if ($existing->trashed() || ! $existing->is_active || $existing->item_type->value !== 'finished_good'
                        || strcasecmp($existing->unit_of_measure, $product->unit_of_measure) !== 0) {
                        throw new \RuntimeException("Inventory mapping for {$product->part_number} needs review; no existing item was changed.");
                    }

                    continue;
                }
                $uom = Uom::whereRaw('LOWER(code) = LOWER(?)', [$product->unit_of_measure])->first();
                if (! $uom) {
                    throw new \RuntimeException("Unit of measure for {$product->part_number} is not configured.");
                }
                Item::create([
                    'code' => $product->part_number, 'name' => $product->name, 'category_id' => $category->id,
                    'item_type' => 'finished_good', 'unit_of_measure' => $uom->code,
                    'standard_cost' => $product->standard_cost, 'is_active' => true,
                ]);
            }
        });
    }
}

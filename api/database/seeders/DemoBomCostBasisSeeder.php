<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\CRM\Models\Product;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Services\BomService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoBomCostBasisSeeder extends Seeder
{
    private const DEMO_BATCH_SIZE = '500';

    public function run(): void
    {
        if (! config('app.seed_demo_data')) {
            return;
        }

        $productIds = Product::query()->whereIn('part_number', [
            'WB-001', 'WB-002', 'PC-001', 'PC-002', 'RC-001', 'BB-001', 'BU-001', 'RC-002',
        ])->pluck('id');

        $boms = Bom::query()->active()->whereIn('product_id', $productIds)
            ->where('cost_batch_size', '1')->with('items')->get();

        DB::transaction(function () use ($boms): void {
            foreach ($boms as $bom) {
                $items = $bom->items->map(fn ($item): array => [
                    'item_id' => (int) $item->item_id,
                    'quantity_per_unit' => (string) $item->quantity_per_unit,
                    'unit' => (string) $item->unit,
                    'waste_factor' => (string) $item->waste_factor,
                    'sort_order' => (int) $item->sort_order,
                ])->all();

                app(BomService::class)->update($bom, $items, self::DEMO_BATCH_SIZE);
            }
        });

        $this->command?->info('Published '.count($boms).' demo BOM cost-basis updates (500 pieces per setup lot).');
    }
}

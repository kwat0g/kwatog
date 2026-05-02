<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        $wh = Warehouse::firstOrCreate(
            ['code' => 'MW'],
            ['name' => 'Main Warehouse', 'address' => 'FCIE Special Economic Zone, Dasmariñas, Cavite', 'is_active' => true],
        );

        $zones = [
            ['code' => 'A', 'name' => 'Zone A — Raw Materials',  'zone_type' => 'raw_materials',  'count' => 20],
            ['code' => 'B', 'name' => 'Zone B — Staging',        'zone_type' => 'staging',        'count' => 8],
            ['code' => 'C', 'name' => 'Zone C — Finished Goods', 'zone_type' => 'finished_goods', 'count' => 30],
            ['code' => 'D', 'name' => 'Zone D — Spare Parts',    'zone_type' => 'spare_parts',    'count' => 10],
            ['code' => 'Q', 'name' => 'Zone Q — Quarantine',     'zone_type' => 'quarantine',     'count' => 4],
        ];

        foreach ($zones as $z) {
            $zone = WarehouseZone::firstOrCreate(
                ['warehouse_id' => $wh->id, 'code' => $z['code']],
                ['name' => $z['name'], 'zone_type' => $z['zone_type']],
            );
            for ($i = 1; $i <= $z['count']; $i++) {
                $code = sprintf('%s-%02d', $z['code'], $i);
                WarehouseLocation::firstOrCreate(
                    ['code' => $code],
                    ['zone_id' => $zone->id, 'is_active' => true],
                );
            }
        }

        $this->command?->info('Warehouse: 1 warehouse, 5 zones, 72 locations seeded.');
    }
}

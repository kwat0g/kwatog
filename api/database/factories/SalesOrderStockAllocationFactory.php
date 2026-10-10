<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\CRM\Models\SalesOrderStockAllocation;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SalesOrderStockAllocationFactory extends Factory
{
    protected $model = SalesOrderStockAllocation::class;

    public function definition(): array
    {
        return ['sales_order_item_id' => SalesOrderItem::factory(), 'item_id' => Item::factory(),
            'location_id' => WarehouseLocation::factory(), 'lot_number' => 'TEST-LOT',
            'quantity' => '5.000', 'transferred_quantity' => '0.000', 'consumed_quantity' => '0.000',
            'released_quantity' => '0.000', 'allocated_by' => User::factory(),
            'request_key' => Str::uuid()->toString(), 'payload_fingerprint' => hash('sha256', 'test')];
    }
}

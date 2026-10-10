<?php

declare(strict_types=1);

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemUomConversion;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\MRP\Models\Mold;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Illuminate\Support\Facades\DB;

if (app()->environment() !== 'local' || DB::connection()->getDatabaseName() !== 'ogami_test_audit_20261002') {
    throw new RuntimeException('Refusing browser fixtures outside the audit database.');
}
$manifest = DB::transaction(function (): array {
    $tag = 'OPS-'.now()->format('His').'-'.random_int(1000, 9999);
    $mold = Mold::create(['mold_code' => $tag, 'name' => 'Operations browser test mold', 'product_id' => Product::factory()->create()->id, 'cavity_count' => 1, 'cycle_time_seconds' => 30, 'setup_time_minutes' => 0, 'output_rate_per_hour' => 120, 'lifetime_max_shots' => 1000000,
        'status' => 'available', 'current_shot_count' => 0, 'max_shots_before_maintenance' => 100000]);
    $item = Item::factory()->create(['code' => $tag.'-SP', 'name' => 'Operations test spare grease',
        'item_type' => 'spare_part', 'unit_of_measure' => 'KG', 'standard_cost' => '1.2345']);
    $location = WarehouseLocation::factory()->create();
    app(StockMovementService::class)->move(new StockMovementInput(
        type: StockMovementType::GrnReceipt, itemId: $item->id, toLocationId: $location->id,
        quantity: '2.000', unitCost: '1.2345', lotNumber: $tag, remarks: 'Isolated browser fixture',
    ));
    $kg = Uom::firstOrCreate(['code' => 'KG'], ['name' => 'Kilogram']);
    $bag = Uom::firstOrCreate(['code' => 'OPSBAG'], ['name' => 'Operations test bag']);
    ItemUomConversion::create(['item_id' => $item->id, 'from_uom_id' => $bag->id, 'to_uom_id' => $kg->id, 'factor' => '5.000000']);
    $po = PurchaseOrder::factory()->create(['status' => PurchaseOrderStatus::Approved,
        'created_by' => User::where('email', 'admin@ogami.test')->value('id')]);
    PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'item_id' => $item->id,
        'description' => $item->name, 'quantity' => '2', 'unit' => 'OPSBAG', 'unit_price' => '5.00', 'total' => '10.00']);

    return ['mold' => $mold->hash_id, 'mold_code' => $mold->mold_code, 'item' => $item->hash_id,
        'item_code' => $item->code, 'location' => $location->hash_id, 'po' => $po->hash_id, 'po_number' => $po->po_number];
});
file_put_contents('/tmp/operations-browser-fixtures.json', json_encode($manifest, JSON_THROW_ON_ERROR));
echo "Fixture ready.\n";

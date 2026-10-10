<?php

declare(strict_types=1);

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Auth\Models\User;
use App\Modules\MRP\Models\Bom;
use Illuminate\Support\Facades\DB;

$tag = (string) getenv('E2E_INVENTORY_TAG');
if (! preg_match('/^[a-z0-9-]{1,6}$/', $tag)) {
    throw new RuntimeException('Set E2E_INVENTORY_TAG to an unused 1–6 character lowercase tag.');
}
$database = DB::connection()->getDatabaseName();
$expectedDatabase = (string) (getenv('INVENTORY_BROWSER_DB') ?: '');
if (app()->environment() !== 'local'
    || $expectedDatabase === ''
    || $database !== $expectedDatabase
    || ! str_starts_with($database, 'ogami_test_inventory_browser_')) {
    throw new RuntimeException('This fixture requires local APP_ENV and its explicitly configured isolated Inventory browser database.');
}

if (Item::query()->whereIn('code', ["E2E-ADJ-{$tag}", "E2E-TRF-{$tag}", "E2E-COUNT-{$tag}"])->exists()
    || Warehouse::query()->where('code', "E2E-SC-WH-{$tag}")->exists()) {
        throw new RuntimeException("Inventory workflow fixture tag {$tag} is already in use.");
}

$fixture = DB::transaction(function () use ($tag): array {
    $warehouseUser = User::query()->where('email', 'warehouse@ogami.test')->firstOrFail();
    $countWarehouse = Warehouse::factory()->create([
        'code' => "E2E-SC-WH-{$tag}",
        'name' => "E2E stock count warehouse {$tag}",
        'is_active' => true,
    ]);
    $countZone = WarehouseZone::factory()->create([
        'warehouse_id' => $countWarehouse->id,
        'name' => "E2E stock count zone {$tag}",
        'code' => "SC-{$tag}",
        'zone_type' => 'raw_materials',
    ]);
    $countLocation = WarehouseLocation::factory()->create([
        'zone_id' => $countZone->id,
        'code' => "SC-{$tag}-01",
        'is_active' => true,
        'is_blocked' => false,
    ]);
    $countItem = Item::factory()->create([
        'code' => "E2E-COUNT-{$tag}",
        'name' => "E2E stock count item {$tag}",
        'unit_of_measure' => 'pcs',
        'item_type' => 'raw_material',
        'standard_cost' => '5.0000',
        'is_active' => true,
    ]);
    app(StockMovementService::class)->move(new StockMovementInput(
        type: StockMovementType::Opening,
        itemId: $countItem->id,
        quantity: '100.000',
        toLocationId: $countLocation->id,
        unitCost: '5.0000',
        remarks: "Explicit opening stock for E2E count {$tag}.",
        createdBy: $warehouseUser->id,
        lotNumber: "COUNT-LOT-{$tag}",
    ));

    foreach (['ADJ', 'TRF'] as $purpose) {
        Item::factory()->create([
        'code' => "E2E-{$purpose}-{$tag}",
        'name' => "Inventory workflow {$purpose} {$tag}",
        'item_type' => 'raw_material',
        'unit_of_measure' => $purpose === 'TRF' ? 'kg' : 'pcs',
        'is_active' => true,
        ]);
    }

    // Explicit MRP starting state for the UOM conversion browser test. The
    // test changes a conversion on PKG-001 and must have live BOM demand so it
    // can verify the real outbox-to-MRP replan path.
    $packaging = Item::query()->where('code', 'PKG-001')->firstOrFail();
    $uomProduct = Product::factory()->create([
        'part_number' => "E2E-UOM-{$tag}",
        'name' => "UOM replan product {$tag}",
        'unit_of_measure' => 'pcs',
        'is_active' => true,
    ]);
    $bom = Bom::query()->create([
        'product_id' => $uomProduct->id,
        'version' => 1,
        'is_active' => true,
    ]);
    $bom->items()->create([
        'item_id' => $packaging->id,
        'quantity_per_unit' => '1.000',
        'unit' => 'pcs',
        'waste_factor' => '0.00',
        'sort_order' => 0,
    ]);
    $salesOrder = SalesOrder::factory()->create([
        'so_number' => "SO-E2E-UOM-{$tag}",
        'created_by' => $warehouseUser->id,
        'status' => 'confirmed',
        'confirmed_at' => now(),
        'date' => now()->toDateString(),
        'subtotal' => '100.00',
        'vat_amount' => '0.00',
        'total_amount' => '100.00',
    ]);
    $salesOrder->items()->create([
        'product_id' => $uomProduct->id,
        'quantity' => '10.00',
        'unit_price' => '10.00',
        'total' => '100.00',
        'quantity_delivered' => '0.00',
        'delivery_date' => now()->addDays(30)->toDateString(),
    ]);

    return [
        'tag' => $tag,
        'count_item' => $countItem->hash_id,
        'count_warehouse' => $countWarehouse->hash_id,
        'count_zone' => $countZone->hash_id,
        'count_location' => $countLocation->hash_id,
        'uom_replan_product' => $uomProduct->hash_id,
        'uom_replan_sales_order' => $salesOrder->hash_id,
    ];
});

$json = json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
file_put_contents(storage_path("app/inventory-workflow-e2e-{$tag}.json"), $json);
echo $json, PHP_EOL;

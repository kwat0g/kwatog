<?php

declare(strict_types=1);

// Run through Artisan Tinker against the isolated real-browser audit database.
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;

$tag = (string) getenv('E2E_SHIPMENT_TAG');
if (! preg_match('/^[a-z0-9-]{1,6}$/', $tag)) {
    throw new RuntimeException('Set E2E_SHIPMENT_TAG to an unused 1–6 character lowercase tag.');
}
if (app()->environment() !== 'local'
    || config('database.connections.pgsql.database') !== 'ogami_test_audit_20261002') {
    throw new RuntimeException('This fixture requires local APP_ENV and database ogami_test_audit_20261002.');
}

$buyer = User::query()->where('email', 'purchasing@ogami.test')->firstOrFail();
$vendor = Vendor::factory()->create(['name' => "Shipment E2E {$tag} supplier"]);
$item = Item::factory()->create([
    'code' => "RM-SHIP-{$tag}",
    'name' => "Shipment E2E {$tag} resin",
    'item_type' => 'raw_material',
    'unit_of_measure' => 'KG',
]);
$po = PurchaseOrder::factory()->create([
    'po_number' => "PO-SHIP-{$tag}",
    'vendor_id' => $vendor->id,
    'created_by' => $buyer->id,
    'incoterm' => 'FOB',
]);
// This test starts at a supplier-sent PO; it creates no later-stage records.
$po->forceFill(['status' => 'sent'])->save();
$poItem = PurchaseOrderItem::query()->create([
    'purchase_order_id' => $po->id,
    'item_id' => $item->id,
    'description' => $item->name,
    'quantity' => '2.000',
    'unit' => 'KG',
    'unit_price' => '10.00',
    'total' => '20.00',
    'quantity_received' => '0.000',
    'quantity_accepted' => '0.000',
]);

$manifest = [
    'tag' => $tag,
    'po' => $po->hash_id,
    'po_number' => $po->po_number,
    'po_item' => $poItem->hash_id,
    'item' => $item->hash_id,
    'incoterm' => $po->incoterm->value,
];
file_put_contents(
    storage_path("app/shipment-create-e2e-{$tag}.json"),
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
);
echo "Shipment Incoterm UI fixture {$tag} ready.\n";

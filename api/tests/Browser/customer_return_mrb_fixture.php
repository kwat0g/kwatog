<?php

declare(strict_types=1);

// Explicit starting state for the customer-return E2E: a finalized source
// invoice. The browser creates the RMA and every later lifecycle record.
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\InvoiceItem;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Quality\Services\InspectionSpecService;
use Illuminate\Support\Facades\DB;

$tag = (string) getenv('E2E_RMA_TAG');
if (! preg_match('/^[a-z0-9-]{1,6}$/D', $tag)) {
    throw new RuntimeException('Set E2E_RMA_TAG to an unused 1–6 character lowercase tag.');
}
if (app()->environment() !== 'local' || DB::connection()->getDatabaseName() !== 'ogami_test_audit_20261002') {
    throw new RuntimeException('Refusing customer-return fixtures outside the isolated audit database.');
}
if (Invoice::query()->where('invoice_number', "INV-RMA-{$tag}")->exists()) {
    throw new RuntimeException("Customer-return fixture {$tag} already exists; use a fresh tag.");
}

$fixture = DB::transaction(function () use ($tag): array {
    $finance = User::query()->where('email', 'finance@ogami.test')->firstOrFail();
    $quality = User::query()->where('email', 'qc@ogami.test')->firstOrFail();
    $revenue = Account::query()->where('code', '4010')->firstOrFail();
    $customer = Customer::query()->create([
        'name' => "Customer Return E2E {$tag}",
        'payment_terms_days' => 30,
        'is_active' => true,
    ]);
    $product = Product::factory()->create([
        'part_number' => "RMA-{$tag}-FG",
        'name' => "Customer return finished good {$tag}",
        'revenue_account_id' => $revenue->id,
        'is_active' => true,
    ]);
    $item = Item::factory()->create([
        'code' => $product->part_number,
        'name' => $product->name,
        'item_type' => ItemType::FinishedGood->value,
        'unit_of_measure' => 'pcs',
        'is_active' => true,
    ]);
    $invoice = Invoice::query()->create([
        'invoice_number' => "INV-RMA-{$tag}",
        'customer_id' => $customer->id,
        'status' => 'finalized',
        'subtotal' => '100.00',
        'vat_amount' => '0.00',
        'total_amount' => '100.00',
        'balance' => '100.00',
        'date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'created_by' => $finance->id,
    ]);
    $line = InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'revenue_account_id' => $revenue->id,
        'description' => $product->part_number,
        'quantity' => '1.000',
        'unit_price' => '100.00',
        'total' => '100.00',
    ]);

    app(InspectionSpecService::class)->upsertForProduct($product->id, [
        [
            'parameter_name' => 'Surface condition',
            'parameter_type' => 'visual',
            'acceptance_criterion' => 'No visible damage or contamination; compare against the approved sample.',
            'is_critical' => true,
        ],
    ], $quality->id, "Customer return E2E {$tag}");

    $quarantine = WarehouseLocation::query()
        ->with(['zone.warehouse'])
        ->where('is_active', true)
        ->whereHas('zone', fn ($query) => $query->where('zone_type', 'quarantine'))
        ->whereHas('zone.warehouse', fn ($query) => $query->where('is_active', true))
        ->orderBy('id')
        ->first();
    if (! $quarantine) {
        $warehouse = Warehouse::factory()->create([
            'code' => "RMA{$tag}W",
            'name' => "Customer return E2E {$tag}",
            'is_active' => true,
        ]);
        $zone = WarehouseZone::factory()->create([
            'warehouse_id' => $warehouse->id,
            'code' => "RQ{$tag}",
            'name' => "Customer return quarantine {$tag}",
            'zone_type' => 'quarantine',
        ]);
        $quarantine = WarehouseLocation::factory()->create([
            'zone_id' => $zone->id,
            'code' => "RQ{$tag}",
            'is_active' => true,
        ]);
        $quarantine->load('zone.warehouse');
    }

    $warehouseId = (int) $quarantine->zone->warehouse_id;
    $targetZone = WarehouseZone::query()
        ->where('warehouse_id', $warehouseId)
        ->where('zone_type', 'finished_goods')
        ->orderBy('id')
        ->first();
    if (! $targetZone) {
        $targetZone = WarehouseZone::factory()->create([
            'warehouse_id' => $warehouseId,
            'code' => "RF{$tag}",
            'name' => "Customer return good stock {$tag}",
            'zone_type' => 'finished_goods',
        ]);
    }
    $target = WarehouseLocation::factory()->create([
        'zone_id' => $targetZone->id,
        'code' => "RF{$tag}",
        'is_active' => true,
    ]);

    return [
        'tag' => $tag,
        'database' => DB::connection()->getDatabaseName(),
        'customer' => $customer->hash_id,
        'product' => $product->hash_id,
        'part_number' => $product->part_number,
        'item' => $item->hash_id,
        'invoice' => $invoice->hash_id,
        'invoice_number' => $invoice->invoice_number,
        'invoice_line' => $line->hash_id,
        'unit_price' => '100.00',
        'quantity' => '1.000',
        'quarantine_location' => $quarantine->hash_id,
        'quarantine_warehouse' => $quarantine->zone->warehouse->hash_id,
        'target_location' => $target->hash_id,
    ];
});

$manifest = storage_path("app/customer-return-mrb-e2e-{$tag}.json");
file_put_contents($manifest, json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($fixture, JSON_THROW_ON_ERROR), PHP_EOL;

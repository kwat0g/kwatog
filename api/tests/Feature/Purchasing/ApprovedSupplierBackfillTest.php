<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 — PO-history backfill of provisional approved-supplier links.
 */
class ApprovedSupplierBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function poWithItem(Vendor $vendor, Item $item, PurchaseOrderStatus $status): void
    {
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $po->forceFill(['status' => $status->value])->save();

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Historical line',
            'quantity' => 10,
            'unit_price' => 5,
            'total' => 50,
        ]);
    }

    public function test_backfill_creates_provisional_links_and_preserves_approved_rows(): void
    {
        $vendor = Vendor::factory()->create();
        $itemA = Item::factory()->create();
        $itemB = Item::factory()->create();

        $this->poWithItem($vendor, $itemA, PurchaseOrderStatus::Approved);
        $this->poWithItem($vendor, $itemB, PurchaseOrderStatus::Received);

        // Pre-existing, reviewed link for item A must never be downgraded.
        ApprovedSupplier::create([
            'item_id' => $itemA->id,
            'vendor_id' => $vendor->id,
            'qualification_status' => ApprovedSupplier::QUALIFICATION_APPROVED,
        ]);

        $this->artisan('purchasing:backfill-approved-suppliers')->assertSuccessful();

        $this->assertSame(
            ApprovedSupplier::QUALIFICATION_APPROVED,
            ApprovedSupplier::query()->where('item_id', $itemA->id)->where('vendor_id', $vendor->id)->value('qualification_status'),
        );
        $this->assertSame(
            ApprovedSupplier::QUALIFICATION_PROVISIONAL,
            ApprovedSupplier::query()->where('item_id', $itemB->id)->where('vendor_id', $vendor->id)->value('qualification_status'),
        );
        $this->assertDatabaseCount('approved_suppliers', 2);
    }

    public function test_backfill_ignores_cancelled_purchase_orders(): void
    {
        $vendor = Vendor::factory()->create();
        $item = Item::factory()->create();

        $this->poWithItem($vendor, $item, PurchaseOrderStatus::Cancelled);

        $this->artisan('purchasing:backfill-approved-suppliers')->assertSuccessful();

        $this->assertDatabaseCount('approved_suppliers', 0);
    }

    public function test_backfill_dry_run_writes_nothing(): void
    {
        $vendor = Vendor::factory()->create();
        $item = Item::factory()->create();

        $this->poWithItem($vendor, $item, PurchaseOrderStatus::Approved);

        $this->artisan('purchasing:backfill-approved-suppliers', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseCount('approved_suppliers', 0);
    }
}

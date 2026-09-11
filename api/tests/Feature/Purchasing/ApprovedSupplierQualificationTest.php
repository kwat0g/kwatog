<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Services\ApprovedSupplierService;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-11 — The ASL is honest again. Approving a PO no longer silently
 * qualifies a vendor for every item it shipped; it records a provisional link
 * that purchasing can review, and never demotes an already-qualified one.
 */
class ApprovedSupplierQualificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(WorkflowSeeder::class);
    }

    private function user(string $roleSlug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
        ]);
    }

    private function poWithItem(User $creator, Vendor $vendor, Item $item, string $price = '1000.00'): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-'.substr(uniqid(), -6),
            'vendor_id' => $vendor->id,
            'date' => now()->toDateString(),
            'subtotal' => $price,
            'vat_amount' => '0.00',
            'total_amount' => $price,
            'is_vatable' => false,
            'created_by' => $creator->id,
        ]);
        $po->forceFill(['status' => PurchaseOrderStatus::Draft->value])->save();
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'PO line',
            'quantity' => '1.00',
            'unit' => 'pcs',
            'unit_price' => $price,
            'total' => $price,
        ]);

        return $po;
    }

    public function test_approving_a_po_records_a_provisional_link(): void
    {
        $buyer = $this->user('purchasing_officer');
        // Unknown creator so the vendor-creator SoD guard cannot fire; the VP
        // and Finance are the checkers, so creator identity does not matter here.
        $vendor = Vendor::factory()->create(['created_by' => null]);
        $item = Item::factory()->create();

        $svc = app(PurchaseOrderService::class);
        $pending = $svc->submit($this->poWithItem($buyer, $vendor, $item));
        $svc->approve($pending->fresh(), $this->user('finance_officer'));

        $this->assertDatabaseHas('approved_suppliers', [
            'item_id' => $item->id,
            'vendor_id' => $vendor->id,
            'qualification_status' => ApprovedSupplier::QUALIFICATION_PROVISIONAL,
        ]);
    }

    public function test_existing_qualified_link_is_not_demoted(): void
    {
        $buyer = $this->user('purchasing_officer');
        $vendor = Vendor::factory()->create(['created_by' => null]);
        $item = Item::factory()->create();

        ApprovedSupplier::create([
            'item_id' => $item->id,
            'vendor_id' => $vendor->id,
            'qualification_status' => ApprovedSupplier::QUALIFICATION_APPROVED,
            'last_price' => '1.00',
            'last_price_at' => now()->subMonth(),
        ]);

        $svc = app(PurchaseOrderService::class);
        $pending = $svc->submit($this->poWithItem($buyer, $vendor, $item, '1000.00'));
        $svc->approve($pending->fresh(), $this->user('finance_officer'));

        $row = ApprovedSupplier::query()
            ->where('item_id', $item->id)
            ->where('vendor_id', $vendor->id)
            ->firstOrFail();
        $this->assertSame(ApprovedSupplier::QUALIFICATION_APPROVED, $row->qualification_status);
        $this->assertSame('1000.00', (string) $row->last_price);
    }

    public function test_soft_deleted_link_can_be_re_added(): void
    {
        $item = Item::factory()->create();
        $vendor = Vendor::factory()->create();

        $row = ApprovedSupplier::create([
            'item_id' => $item->id,
            'vendor_id' => $vendor->id,
            'qualification_status' => ApprovedSupplier::QUALIFICATION_APPROVED,
        ]);
        $row->delete();
        $this->assertSoftDeleted('approved_suppliers', ['id' => $row->id]);

        // The old unique index ignored deleted_at, so this insert used to throw
        // SQLSTATE 23505. The partial unique index scoped to live rows fixes it.
        $replacement = app(ApprovedSupplierService::class)->create([
            'item_id' => $item->id,
            'vendor_id' => $vendor->id,
            'lead_time_days' => 3,
        ]);

        $this->assertNotSame($row->id, $replacement->id);
        $this->assertNull($replacement->deleted_at);
    }
}

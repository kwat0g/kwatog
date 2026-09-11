<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Services\AlertEngineService;
use App\Common\Services\ApprovalService;
use App\Common\Services\NotificationService;
use App\Common\Services\TaxPolicyService;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\AutoPurchaseOrderService;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AutoPurchaseOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingsSeeder::class);
        $this->seed(WorkflowSeeder::class);

        $this->app->instance(
            AlertEngineService::class,
            Mockery::mock(AlertEngineService::class),
        );
        $this->app->instance(
            TaxPolicyService::class,
            Mockery::mock(TaxPolicyService::class),
        );
        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('send')->zeroOrMoreTimes();
        $this->app->instance(NotificationService::class, $notifications);
    }

    public function test_critical_shortage_creates_a_canonical_pending_approval_po_once(): void
    {
        $alerts = Mockery::mock(AlertEngineService::class);
        $alerts->shouldReceive('raise')->once();
        $this->app->instance(AlertEngineService::class, $alerts);

        $tax = Mockery::mock(TaxPolicyService::class);
        $tax->shouldReceive('isVatRegistered')->andReturn(false);
        $this->app->instance(TaxPolicyService::class, $tax);

        $item = Item::factory()->create([
            'is_critical'   => true,
            'reorder_point' => '10.00',
            'safety_stock'  => '2.00',
            'standard_cost' => '10.00',
        ]);
        $location = WarehouseLocation::factory()->create();
        StockLevel::factory()->create([
            'item_id'     => $item->id,
            'location_id' => $location->id,
            'quantity'    => '0.00',
        ]);
        $vendor = Vendor::factory()->create();
        ApprovedSupplier::create([
            'item_id'       => $item->id,
            'vendor_id'     => $vendor->id,
            'is_preferred'  => true,
            'lead_time_days' => 5,
            'last_price'     => '12.34',
        ]);

        $service = $this->app->make(AutoPurchaseOrderService::class);
        $po = $service->createForCriticalShortage($item);

        self::assertInstanceOf(PurchaseOrder::class, $po);
        self::assertSame(PurchaseOrderStatus::PendingApproval, $po->fresh()->status);
        self::assertSame('148.08', (string) $po->fresh()->total_amount);
        self::assertDatabaseHas('purchase_orders', [
            'id'     => $po->id,
            'status' => PurchaseOrderStatus::PendingApproval->value,
        ]);
        self::assertDatabaseMissing('purchase_orders', ['status' => 'pending_vp']);

        // Money-only PO chain: Finance (step 1) pending, VP (step 2, ₱50k
        // threshold) skipped because this auto-PO is ₱148.08.
        $records = app(ApprovalService::class)->currentChain($po->fresh());
        self::assertCount(2, $records);
        self::assertSame('pending', $records->firstWhere('step_order', 1)?->action);
        self::assertSame('skipped', $records->firstWhere('step_order', 2)?->action);

        self::assertNull($service->createForCriticalShortage($item->fresh()));
        self::assertSame(1, PurchaseOrder::query()->where('is_auto_generated', true)->count());
    }
}

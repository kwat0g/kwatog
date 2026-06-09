<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Events\PurchaseOrderCancelled;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 3 — M-12. PurchaseOrderService::cancel() must:
 *  - Wrap the status flip + remarks update in DB::transaction.
 *  - Fire PurchaseOrderCancelled after commit so listeners react only to
 *    durably-cancelled POs.
 *  - Honour the existing guards: a Received/Closed PO cannot be cancelled,
 *    and a PO with attached GRNs cannot be cancelled.
 */
class PurchaseOrderCancelTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseOrderService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->svc = app(PurchaseOrderService::class);
    }

    public function test_cancel_flips_status_and_dispatches_cancelled_event_after_commit(): void
    {
        Event::fake([PurchaseOrderCancelled::class]);

        $po = PurchaseOrder::factory()->create([
            'status'     => PurchaseOrderStatus::Approved->value,
            'created_by' => User::factory()->create()->id,
        ]);

        $result = $this->svc->cancel($po, 'Vendor backed out');

        $this->assertSame(PurchaseOrderStatus::Cancelled, $result->status);
        $this->assertStringContainsString('Cancelled: Vendor backed out', (string) $result->remarks);

        Event::assertDispatched(
            PurchaseOrderCancelled::class,
            fn (PurchaseOrderCancelled $e) => $e->purchaseOrder->id === $po->id,
        );
    }

    public function test_cancel_refuses_received_po(): void
    {
        $po = PurchaseOrder::factory()->create([
            'status'     => PurchaseOrderStatus::Received->value,
            'created_by' => User::factory()->create()->id,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot cancel a fully received or closed PO.');

        $this->svc->cancel($po, 'too late');
    }

    public function test_cancel_refuses_closed_po(): void
    {
        $po = PurchaseOrder::factory()->create([
            'status'     => PurchaseOrderStatus::Closed->value,
            'created_by' => User::factory()->create()->id,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot cancel a fully received or closed PO.');

        $this->svc->cancel($po, 'too late');
    }

    public function test_cancel_refuses_po_with_grns(): void
    {
        $user = User::factory()->create();
        $po = PurchaseOrder::factory()->create([
            'status'     => PurchaseOrderStatus::Approved->value,
            'created_by' => $user->id,
        ]);

        GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $po->id,
            'vendor_id'         => $po->vendor_id,
            'received_by'       => $user->id,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot cancel a PO with GRNs.');

        $this->svc->cancel($po, 'no');
    }
}

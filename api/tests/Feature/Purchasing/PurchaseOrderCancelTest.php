<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Services\GrnService;
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

        // A3 — only a NON-draft GRN blocks cancellation. The factory default
        // status is `pending_qc`, so the guard must still fire here; a `draft`
        // GRN (auto-staged on send) must not.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot cancel a PO with received goods.');

        $this->svc->cancel($po, 'no');
    }

    public function test_cancel_rechecks_the_authoritative_row_before_overwriting_a_stale_request(): void
    {
        $po = PurchaseOrder::factory()->create([
            'status'     => PurchaseOrderStatus::Approved->value,
            'created_by' => User::factory()->create()->id,
        ]);
        $stale = $po->fresh();

        $po->forceFill(['status' => PurchaseOrderStatus::Received])->save();

        try {
            $this->svc->cancel($stale, 'stale request');
            $this->fail('A stale cancellation request must be rejected.');
        } catch (RuntimeException $e) {
            $this->assertSame('Cannot cancel a fully received or closed PO.', $e->getMessage());
        }
        $this->assertSame(PurchaseOrderStatus::Received, $po->fresh()->status);
    }

    public function test_cancelling_po_purges_staged_draft_grns_and_prevents_receiving(): void
    {
        $user = User::factory()->create();
        $po = PurchaseOrder::factory()->create([
            'status'     => PurchaseOrderStatus::Approved->value,
            'created_by' => $user->id,
        ]);

        $draftGrn = GoodsReceiptNote::create([
            'grn_number' => 'GRN-DRAFT-PURGE',
            'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id,
            'status' => GrnStatus::Draft,
            'received_date' => now()->toDateString(),
            'received_by' => $user->id,
        ]);

        $this->svc->cancel($po, 'Supplier cancelled order');

        $this->assertSame(PurchaseOrderStatus::Cancelled, $po->fresh()->status);
        $this->assertDatabaseMissing('goods_receipt_notes', ['id' => $draftGrn->id]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('not open for receiving');

        app(GrnService::class)->create($po->fresh(), [], [], $user);
    }

    public function test_cancel_refuses_po_with_non_cancelled_bill(): void
    {
        $user = User::factory()->create();
        $po = PurchaseOrder::factory()->create([
            'status'     => PurchaseOrderStatus::Approved->value,
            'created_by' => $user->id,
        ]);

        // Create a non-cancelled bill (e.g., unpaid service bill)
        Bill::create([
            'bill_number' => 'BILL-'.uniqid(),
            'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id,
            'total_amount' => '100.00',
            'status' => 'unpaid',
            'date' => now(),
            'due_date' => now()->addDays(30),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot cancel a PO that has linked bills');

        $this->svc->cancel($po, 'no bills allowed');
    }

    public function test_cancel_refuses_po_with_draft_bill(): void
    {
        $user = User::factory()->create();
        $po = PurchaseOrder::factory()->create([
            'status'     => PurchaseOrderStatus::Approved->value,
            'created_by' => $user->id,
        ]);

        // Create a draft bill (auto-created on GRN acceptance)
        Bill::create([
            'bill_number' => 'BILL-'.uniqid(),
            'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id,
            'total_amount' => '100.00',
            'status' => 'draft',
            'date' => now(),
            'due_date' => now()->addDays(30),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot cancel a PO that has linked bills');

        $this->svc->cancel($po, 'no bills allowed');
    }

    public function test_cancel_allows_po_with_cancelled_bill(): void
    {
        $user = User::factory()->create();
        $po = PurchaseOrder::factory()->create([
            'status'     => PurchaseOrderStatus::Approved->value,
            'created_by' => $user->id,
        ]);

        // Create a cancelled bill (should not block cancellation)
        Bill::create([
            'bill_number' => 'BILL-'.uniqid(),
            'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id,
            'total_amount' => '100.00',
            'status' => 'cancelled',
            'date' => now(),
            'due_date' => now()->addDays(30),
        ]);

        $result = $this->svc->cancel($po, 'cancelled bill ok');

        $this->assertSame(PurchaseOrderStatus::Cancelled, $result->status);
    }

    public function test_cancel_allows_po_with_rejected_grn(): void
    {
        $user = User::factory()->create();
        $po = PurchaseOrder::factory()->create([
            'status'     => PurchaseOrderStatus::Approved->value,
            'created_by' => $user->id,
        ]);

        // Create a rejected GRN (supplier rejected goods; should not block)
        GoodsReceiptNote::create([
            'grn_number' => 'GRN-REJECTED-OK',
            'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id,
            'status' => GrnStatus::Rejected,
            'received_date' => now()->toDateString(),
            'received_by' => $user->id,
        ]);

        $result = $this->svc->cancel($po, 'rejected goods ok');

        $this->assertSame(PurchaseOrderStatus::Cancelled, $result->status);
    }
}

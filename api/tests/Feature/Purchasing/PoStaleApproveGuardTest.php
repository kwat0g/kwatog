<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PO approve guard regression — the P01-01 shape on the P2P approval boundary.
 *
 * approve() now re-reads the authoritative PO row under a row lock inside the
 * transaction before any approvals work. A stale approver instance acting after
 * the PO already advanced must get the clean "not in an approvable state"
 * rejection instead of a misleading downstream error or a second approval.
 */
class PoStaleApproveGuardTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseOrderService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->svc = app(PurchaseOrderService::class);
    }

    private function pendingPo(): PurchaseOrder
    {
        $user = User::factory()->create(['is_active' => true]);

        $po = PurchaseOrder::create([
            'po_number'    => 'PO-' . substr(uniqid(), -6),
            'vendor_id'    => \App\Modules\Accounting\Models\Vendor::create([
                'name'              => 'Vendor-' . substr(uniqid(), -5),
                'payment_terms_days'=> 30,
            ])->id,
            'date'         => now()->toDateString(),
            'total_amount' => '0.00',
            'created_by'   => $user->id,
        ]);
        // status is non-fillable; service-only.
        $po->forceFill(['status' => PurchaseOrderStatus::PendingApproval->value])->save();

        return $po;
    }

    public function test_approve_with_stale_pending_instance_after_advance_is_blocked(): void
    {
        $po = $this->pendingPo();
        $this->assertSame(PurchaseOrderStatus::PendingApproval, $po->status);

        // Two approvers each read the row while it was pending approval.
        $approverA = PurchaseOrder::query()->findOrFail($po->id);
        $approverB = PurchaseOrder::query()->findOrFail($po->id);

        // Approver A's request commits first (approve path advanced the status).
        $approverA->forceFill(['status' => PurchaseOrderStatus::Approved->value])->save();

        // Approver B acts on its stale instance — must be rejected cleanly by
        // the locked re-read, before any approvals work runs.
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('not in an approvable state');

        $this->svc->approve($approverB, User::factory()->create(['is_active' => true]));
    }
}

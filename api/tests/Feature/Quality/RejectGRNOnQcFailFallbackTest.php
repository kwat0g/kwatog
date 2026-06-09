<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Events\InspectionFailed;
use App\Modules\Quality\Listeners\RejectGRNOnQcFail;
use App\Modules\Quality\Models\Inspection;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3 — M-13. RejectGRNOnQcFail must fall back to a system_admin user
 * when the failed inspection has no inspector_id (auto-created or imported
 * inspections), instead of silently skipping the GRN reject.
 */
class RejectGRNOnQcFailFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_listener_falls_back_to_system_admin_when_inspector_missing(): void
    {
        // Active system_admin to act as fallback actor.
        $sysAdminRoleId = Role::where('slug', 'system_admin')->value('id');
        $sysAdmin = User::factory()->create([
            'role_id'   => $sysAdminRoleId,
            'is_active' => true,
        ]);

        // GRN sitting at pending_qc with no inspector on the failed inspection.
        $grn = $this->seedGrn();
        $inspection = $this->seedFailedIncomingInspectionFor($grn, inspectorId: null);

        $listener = new RejectGRNOnQcFail(app(GrnService::class));
        $listener->handle(new InspectionFailed($inspection));

        $fresh = $grn->fresh();
        $this->assertSame(GrnStatus::Rejected, $fresh->status,
            'Listener must reject the GRN using the system_admin fallback when inspector_id is null.');
        $this->assertSame($sysAdmin->id, $fresh->accepted_by,
            'Reject attribution must point at the fallback system_admin user.');
        $this->assertStringContainsString('Auto-rejected', (string) $fresh->rejected_reason);
    }

    public function test_listener_uses_inspector_when_present(): void
    {
        // Pre-create a system_admin so we can prove it was NOT used.
        $sysAdminRoleId = Role::where('slug', 'system_admin')->value('id');
        User::factory()->create([
            'role_id'   => $sysAdminRoleId,
            'is_active' => true,
        ]);

        $inspectorRoleId = Role::where('slug', 'qc_inspector')->value('id') ?? $sysAdminRoleId;
        $inspector = User::factory()->create([
            'role_id'   => $inspectorRoleId,
            'is_active' => true,
        ]);

        $grn = $this->seedGrn();
        $inspection = $this->seedFailedIncomingInspectionFor($grn, inspectorId: $inspector->id);

        $listener = new RejectGRNOnQcFail(app(GrnService::class));
        $listener->handle(new InspectionFailed($inspection));

        $fresh = $grn->fresh();
        $this->assertSame(GrnStatus::Rejected, $fresh->status);
        $this->assertSame($inspector->id, $fresh->accepted_by,
            'Inspector must be preferred over the system_admin fallback when present.');
    }

    public function test_listener_no_ops_when_no_actor_available(): void
    {
        // No system_admin user exists; inspection has no inspector either.
        // The listener must not throw and must leave the GRN at pending_qc.
        $grn = $this->seedGrn();
        $inspection = $this->seedFailedIncomingInspectionFor($grn, inspectorId: null);

        $listener = new RejectGRNOnQcFail(app(GrnService::class));
        $listener->handle(new InspectionFailed($inspection));

        $this->assertSame(GrnStatus::PendingQc, $grn->fresh()->status,
            'With no actor available the GRN must remain at pending_qc, not be rejected.');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function seedGrn(): GoodsReceiptNote
    {
        return GoodsReceiptNote::factory()->create([
            'status' => GrnStatus::PendingQc->value,
        ]);
    }

    private function seedFailedIncomingInspectionFor(GoodsReceiptNote $grn, ?int $inspectorId): Inspection
    {
        $product = Product::create([
            'part_number'     => strtoupper(substr(uniqid('PT-'), 0, 12)),
            'name'            => 'M13 Resin ' . uniqid(),
            'unit_of_measure' => 'kg',
            'standard_cost'   => '50.00',
            'is_active'       => true,
        ]);

        return Inspection::create([
            'inspection_number' => 'QC-M13-' . substr(uniqid(), -8),
            'stage'             => InspectionStage::Incoming->value,
            'status'            => InspectionStatus::Failed->value,
            'product_id'        => $product->id,
            'entity_type'       => InspectionEntityType::Grn->value,
            'entity_id'         => $grn->id,
            'batch_quantity'    => 100,
            'sample_size'       => 13,
            'accept_count'      => 0,
            'reject_count'      => 1,
            'defect_count'      => 1,
            'inspector_id'      => $inspectorId,
            'completed_at'      => now(),
        ]);
    }
}

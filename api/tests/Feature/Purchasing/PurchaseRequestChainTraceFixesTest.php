<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Events\ChainStepAdvanced;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Enums\IncomingQcHandoffStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\MRP\Enums\MrpPlanStatus;
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-18 — regression coverage for the internal-PR chain-trace fixes:
 *
 *   §7.5  PR now broadcasts chain progress (submit / approve / cancel /
 *         reject / converted) through ChainBroadcaster.
 *   §7.4  PR create() persists an optional mrp_plan_id provenance link.
 *   §7.7  coa_verified flips true when the accepted line carries a CoA
 *         document and its incoming inspection passed — and ONLY then.
 *   §7.9  grn:retry-pending-incoming-qc recovers stuck handoffs and
 *         distinguishes "nothing to do" from "everything failed".
 */
class PurchaseRequestChainTraceFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WorkflowSeeder::class);
    }

    // ── §7.5 — PR chain broadcasts ─────────────────────────────────────────

    public function test_submit_stages_a_durable_chain_step_event(): void
    {
        $pr = PurchaseRequest::factory()->create();

        app(\App\Modules\Purchasing\Services\PurchaseRequestService::class)->submit($pr);

        $this->assertDatabaseHas('event_outbox', [
            'event_type' => ChainStepAdvanced::class,
        ]);
        // The sync test queue dispatches immediately after commit, so the
        // ledger row is already 'published' rather than 'pending'.
        $this->assertDatabaseHas('chain_step_runs', [
            'entity_id' => $pr->id,
            'step' => 'pending',
            'status' => 'published',
        ]);
    }

    public function test_terminal_statuses_map_through_the_pr_chain_definition(): void
    {
        // resolveStrict throws for an unmapped status — this loop pins every
        // lifecycle status the service can broadcast as resolvable, so a new
        // PurchaseRequestStatus case cannot silently break the broadcaster.
        foreach (['draft', 'pending', 'approved', 'partial', 'converted', 'rejected', 'cancelled'] as $status) {
            [$active, $completed] = \App\Common\Support\ChainDefinitions::resolveStrict('purchase_request', $status);
            $this->assertContains($active, ['draft', 'pending', 'approved', 'converted'], $status);
        }

        [$approvedActive, $approvedCompleted] = \App\Common\Support\ChainDefinitions::resolveStrict('purchase_request', 'approved');
        $this->assertSame(['draft', 'pending'], $approvedCompleted);

        [$convertedActive, $convertedCompleted] = \App\Common\Support\ChainDefinitions::resolveStrict('purchase_request', 'converted');
        $this->assertSame('converted', $convertedActive);
        $this->assertSame(['draft', 'pending', 'approved'], $convertedCompleted);
    }

    // ── §7.4 — mrp_plan_id provenance ──────────────────────────────────────

    public function test_create_persists_mrp_plan_link(): void
    {
        // MrpPlan has no factory; mirror MrpPlanDetailTest's explicit create.
        $admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        $salesOrder = SalesOrder::factory()->create();
        $plan = MrpPlan::create([
            'mrp_plan_no' => 'MRP-'.now()->format('Ym').'-'.fake()->unique()->numerify('####'),
            'sales_order_id' => $salesOrder->id,
            'version' => 1,
            'status' => MrpPlanStatus::Active,
            'generated_by' => $admin->id,
            'generated_at' => now(),
        ]);

        $pr = app(\App\Modules\Purchasing\Services\PurchaseRequestService::class)->create(
            [
                'sourcing_method' => 'direct_po',
                // Service contract is the raw int; the HTTP layer's
                // StorePurchaseRequestRequest resolves the HashID.
                'mrp_plan_id' => $plan->id,
                'items' => [
                    ['description' => 'Resin lot linked to plan', 'quantity' => '3', 'unit' => 'kg'],
                ],
            ],
            $admin,
        );

        $this->assertSame($plan->id, $pr->mrp_plan_id);
    }

    // ── §7.7 — CoA verification on incoming pass ───────────────────────────

    public function test_accept_verifies_coa_for_a_passed_line_with_document(): void
    {
        $grn = $this->makeReceivedGrn(['coa_document_path' => 'coa/resin-lot-42.pdf']);

        // create() already staged a draft per-line inspection (F-06 sync
        // path); complete it as PASSED the way the inspector's verdict would.
        $this->completeLineInspection($grn, 'passed');

        $qcUser = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'qc_inspector')->value('id'),
        ]);
        $accepted = app(GrnService::class)->accept($grn->fresh(), $qcUser);

        $this->assertContains($accepted->status, [GrnStatus::Accepted, GrnStatus::PartialAccepted]);
        $this->assertTrue((bool) $grn->items()->first()->fresh()->coa_verified);
    }

    public function test_accept_does_not_verify_coa_without_a_document(): void
    {
        $grn = $this->makeReceivedGrn(['coa_document_path' => null]);

        $this->completeLineInspection($grn, 'passed');

        $qcUser = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'qc_inspector')->value('id'),
        ]);
        app(GrnService::class)->accept($grn->fresh(), $qcUser);

        $this->assertFalse((bool) $grn->items()->first()->fresh()->coa_verified);
    }

    public function test_accept_does_not_verify_coa_when_the_inspection_failed(): void
    {
        $grn = $this->makeReceivedGrn(['coa_document_path' => 'coa/resin-lot-43.pdf']);

        $this->completeLineInspection($grn, 'failed');

        $qcUser = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'qc_inspector')->value('id'),
        ]);

        // The fail-closed gate refuses acceptance on a failed inspection, so
        // the CoA flag cannot flip through this path either.
        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        try {
            app(GrnService::class)->accept($grn->fresh(), $qcUser);
        } finally {
            $this->assertFalse((bool) $grn->items()->first()->fresh()->coa_verified);
        }
    }

    // ── §7.9 — scheduled retry sweep ───────────────────────────────────────

    public function test_retry_sweep_recovers_a_stuck_grn(): void
    {
        $grn = $this->makeReceivedGrn(['coa_document_path' => null]);
        $grn->forceFill(['incoming_qc_handoff_status' => IncomingQcHandoffStatus::ManualRequired])->save();

        $this->artisan('grn:retry-pending-incoming-qc')->assertExitCode(0);

        $this->assertSame(
            IncomingQcHandoffStatus::Generated->value,
            $grn->fresh()->incoming_qc_handoff_status->value,
        );
        $this->assertNotNull($grn->fresh()->qc_inspection_id);
    }

    public function test_retry_sweep_is_a_clean_noop_when_nothing_is_stuck(): void
    {
        $this->artisan('grn:retry-pending-incoming-qc')
            ->expectsOutputToContain('No GRNs awaiting')
            ->assertExitCode(0);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * A pending_qc GRN with one received raw-material line, matching the
     * GrnIncomingQcHandoffTest scaffold. Options are applied to the single
     * grn_items row (e.g. a CoA document reference).
     *
     * @param  array<string, mixed>  $lineOverrides
     */
    private function makeReceivedGrn(array $lineOverrides = []): GoodsReceiptNote
    {
        $receiver = User::factory()->create();
        $item = Item::factory()->create(['is_active' => true]);
        $po = PurchaseOrder::factory()->create([
            'status' => \App\Modules\Purchasing\Enums\PurchaseOrderStatus::Approved->value,
            'created_by' => $receiver->id,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Trace-fix resin',
            'quantity' => '25.000',
            'unit' => 'kg',
            'unit_price' => '10.00',
            'total' => '250.00',
            'quantity_received' => '0.000',
        ]);
        $location = WarehouseLocation::factory()->create();

        $grn = app(GrnService::class)->create($po, [[
            'purchase_order_item_id' => $poItem->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_received' => '10.000',
            'unit_cost' => '10.00',
        ]], ['received_date' => now()->toDateString()], $receiver);

        if ($lineOverrides !== []) {
            GrnItem::query()->whereKey($grn->items->first()->id)->update($lineOverrides);
        }

        return $grn;
    }

    /** Finalise the auto-staged per-line incoming inspection to $status. */
    private function completeLineInspection(GoodsReceiptNote $grn, string $status): void
    {
        $inspection = \App\Modules\Quality\Models\Inspection::query()
            ->where('stage', 'incoming')
            ->where('grn_item_id', $grn->items->first()->id)
            ->firstOrFail();
        $inspection->forceFill([
            'status' => $status,
            'completed_at' => now(),
        ])->save();
    }
}

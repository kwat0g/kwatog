<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Enums\NcrDisposition;
use App\Modules\Quality\Enums\NcrSeverity;
use App\Modules\Quality\Enums\NcrSource;
use App\Modules\Quality\Enums\NcrStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\InspectionService;
use App\Modules\Quality\Services\NcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P2.10 — IATF Traceability: inspection failure → NCR auto-creation.
 *
 * ARCHITECTURE NOTE — afterCommit behavior confirmed working
 * ────────────────────────────────────────────────────────────
 * InspectionService::complete() wraps the NCR creation inside
 * DB::afterCommit(). Under RefreshDatabase with SQLite in-memory,
 * Laravel's DatabaseTransactionsManager fires afterCommit callbacks
 * when the nested savepoint commits (level 2→1), NOT when the outer
 * test transaction rolls back. This means the full traceability loop
 * WORKS correctly end-to-end in tests — calling complete() on a
 * failing inspection creates the NCR automatically.
 *
 * Test strategy
 * ─────────────
 * 1. test_failed_outgoing_inspection_creates_linked_ncr
 *    – Builds a failed inspection row manually, then calls
 *      NcrService::openFromInspectionFailure() directly.
 *      Pins the NCR creation path and field values.
 *
 * 2. test_passed_inspection_creates_no_ncr
 *    – Calls complete() on a passing inspection; confirms no NCR is
 *      created.
 *
 * 3. test_complete_marks_inspection_failed_when_critical_param_fails
 *    – Drives complete() all the way through; asserts the inspection
 *      status row is 'failed'.
 *
 * 4. test_ncr_severity_is_critical_when_critical_param_fails
 *    – openFromInspectionFailure() on a critical-fail inspection
 *      → severity = 'critical'.
 *
 * 5. test_ncr_severity_is_high_when_defect_count_exceeds_accept_count
 *    – No critical param, but defect_count > accept_count → 'high'.
 *
 * 6. test_open_from_inspection_failure_is_idempotent
 *    – Second call returns the existing NCR; no duplicate rows.
 *
 * 7. test_rework_disposition_does_not_auto_create_work_order
 *    – NcrService::close() with disposition=rework on outgoing NCR
 *      must NOT spawn a replacement WO (only scrap does).
 *
 * 8. test_scrap_disposition_on_outgoing_ncr_auto_creates_replacement_wo
 *    – close() with disposition=scrap on an outgoing-inspection NCR
 *      auto-creates a WorkOrder and back-fills
 *      replacement_work_order_id.
 *
 * 9. test_complete_on_failing_inspection_auto_creates_ncr_via_aftercommit
 *    – End-to-end: complete() on failing inspection → NCR auto-created.
 *      Confirms the afterCommit callback fires correctly.
 */
class InspectionNcrTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Product $product;
    private InspectionSpec $spec;
    private InspectionSpecItem $criticalItem;
    private InspectionService $inspSvc;
    private NcrService $ncrSvc;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['slug' => 'qc_inspector'], ['name' => 'QC Inspector']);

        $this->user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        $this->product = Product::create([
            'part_number'   => 'QC-TEST-001',
            'name'          => 'Test Wiper Bushing',
            'unit_of_measure' => 'pcs',
            'standard_cost' => '10.00',
            'is_active'     => true,
        ]);

        $this->spec = InspectionSpec::create([
            'product_id' => $this->product->id,
            'version'    => 1,
            'is_active'  => true,
            'created_by' => $this->user->id,
        ]);

        // One critical dimensional parameter (shaft OD, tolerance 9.90–10.10 mm)
        $this->criticalItem = InspectionSpecItem::create([
            'inspection_spec_id' => $this->spec->id,
            'parameter_name'     => 'Shaft OD',
            'parameter_type'     => 'dimensional',
            'unit_of_measure'    => 'mm',
            'nominal_value'      => '10.00',
            'tolerance_min'      => '9.90',
            'tolerance_max'      => '10.10',
            'is_critical'        => true,
            'sort_order'         => 1,
        ]);

        $this->inspSvc = app(InspectionService::class);
        $this->ncrSvc  = app(NcrService::class);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Build an Inspection row with one measurement that has is_pass=false
     * and is_critical=$critical. The inspection is stamped with status=failed
     * directly so we can call openFromInspectionFailure() without routing
     * through complete() / afterCommit.
     */
    private function makeFailedInspection(
        bool $criticalFail = false,
        int $batchQty = 100,
        int $defectCount = 5,
        int $acceptCount = 2,
        InspectionStage $stage = InspectionStage::Outgoing,
    ): Inspection {
        $insp = Inspection::create([
            'inspection_number'  => 'QC-TEST-' . uniqid(),
            'stage'              => $stage->value,
            'status'             => InspectionStatus::Failed->value,
            'product_id'         => $this->product->id,
            'inspection_spec_id' => $this->spec->id,
            'batch_quantity'     => $batchQty,
            'sample_size'        => 13, // AQL G for batch 100
            'aql_code'           => 'G',
            'accept_count'       => $acceptCount,
            'reject_count'       => $acceptCount + 1,
            'defect_count'       => $defectCount,
            'inspector_id'       => $this->user->id,
            'started_at'         => now(),
            'completed_at'       => now(),
        ]);

        // Seed one failing measurement row.
        InspectionMeasurement::create([
            'inspection_id'           => $insp->id,
            'inspection_spec_item_id' => $this->criticalItem->id,
            'sample_index'            => 1,
            'parameter_name'          => 'Shaft OD',
            'parameter_type'          => 'dimensional',
            'unit_of_measure'         => 'mm',
            'nominal_value'           => '10.00',
            'tolerance_min'           => '9.90',
            'tolerance_max'           => '10.10',
            'measured_value'          => $criticalFail ? '8.50' : '10.50', // always out of spec for this fixture
            'is_critical'             => $criticalFail,
            'is_pass'                 => false,
        ]);

        return $insp->fresh(['measurements']);
    }

    /**
     * Build an Inspection row where all measurements pass.
     */
    private function makePassedInspectionForComplete(): Inspection
    {
        // Create inspection directly in in_progress state so complete() can run.
        $insp = Inspection::create([
            'inspection_number'  => 'QC-PASS-' . uniqid(),
            'stage'              => InspectionStage::Outgoing->value,
            'status'             => InspectionStatus::InProgress->value,
            'product_id'         => $this->product->id,
            'inspection_spec_id' => $this->spec->id,
            'batch_quantity'     => 50,
            'sample_size'        => 8,  // AQL for batch 50
            'aql_code'           => 'F',
            'accept_count'       => 0,
            'reject_count'       => 1,
            'defect_count'       => 0,
            'inspector_id'       => $this->user->id,
            'started_at'         => now(),
        ]);

        // One measurement that passes (within tolerance).
        InspectionMeasurement::create([
            'inspection_id'           => $insp->id,
            'inspection_spec_item_id' => $this->criticalItem->id,
            'sample_index'            => 1,
            'parameter_name'          => 'Shaft OD',
            'parameter_type'          => 'dimensional',
            'unit_of_measure'         => 'mm',
            'nominal_value'           => '10.00',
            'tolerance_min'           => '9.90',
            'tolerance_max'           => '10.10',
            'measured_value'          => '10.00',   // exactly on nominal → pass
            'is_critical'             => true,
            'is_pass'                 => true,
        ]);

        return $insp->fresh(['measurements']);
    }

    /**
     * Build a failing inspection and pump it through complete() so the
     * inspection row transitions to status=failed. Does NOT guarantee NCR
     * creation (afterCommit gap — see class doc-block).
     */
    private function makeFailedViaComplete(): Inspection
    {
        $insp = Inspection::create([
            'inspection_number'  => 'QC-CMPL-' . uniqid(),
            'stage'              => InspectionStage::Outgoing->value,
            'status'             => InspectionStatus::InProgress->value,
            'product_id'         => $this->product->id,
            'inspection_spec_id' => $this->spec->id,
            'batch_quantity'     => 100,
            'sample_size'        => 13,
            'aql_code'           => 'G',
            'accept_count'       => 2,
            'reject_count'       => 3,
            'defect_count'       => 0,
            'inspector_id'       => $this->user->id,
            'started_at'         => now(),
        ]);

        // Failing measurement (value below tolerance_min).
        InspectionMeasurement::create([
            'inspection_id'           => $insp->id,
            'inspection_spec_item_id' => $this->criticalItem->id,
            'sample_index'            => 1,
            'parameter_name'          => 'Shaft OD',
            'parameter_type'          => 'dimensional',
            'unit_of_measure'         => 'mm',
            'nominal_value'           => '10.00',
            'tolerance_min'           => '9.90',
            'tolerance_max'           => '10.10',
            'measured_value'          => '8.50',  // fails
            'is_critical'             => true,
            'is_pass'                 => false,
        ]);

        return $insp->fresh(['measurements']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Test 1: Failed inspection → NCR created with correct linkage
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Core IATF traceability assertion:
     * NcrService::openFromInspectionFailure() creates exactly one NCR row
     * with source=inspection_fail, inspection_id pointing back to the
     * inspection, and affected_quantity matching batch_quantity.
     */
    public function test_failed_outgoing_inspection_creates_linked_ncr(): void
    {
        $insp = $this->makeFailedInspection(
            criticalFail: false,
            batchQty:    100,
            defectCount: 5,
            acceptCount: 2,
        );

        $ncr = $this->ncrSvc->openFromInspectionFailure($insp, $this->user);

        // One NCR row exists.
        $this->assertSame(
            1,
            NonConformanceReport::where('inspection_id', $insp->id)->count(),
            'Exactly one NCR must be created for a failed inspection',
        );

        // Linked back to the inspection.
        $this->assertSame(
            $insp->id,
            $ncr->inspection_id,
            'NCR.inspection_id must equal the failed inspection id',
        );

        // source = inspection_fail.
        $this->assertSame(
            NcrSource::InspectionFail->value,
            $ncr->source->value,
            'NCR source must be inspection_fail',
        );

        // nonconforming qty = batch_quantity.
        $this->assertSame(
            100,
            $ncr->affected_quantity,
            'NCR.affected_quantity must equal inspection.batch_quantity',
        );

        // Status opens at 'open'.
        $this->assertSame(
            NcrStatus::Open->value,
            $ncr->status->value,
            'Auto-generated NCR must open with status=open',
        );

        // product_id propagated.
        $this->assertSame(
            $this->product->id,
            $ncr->product_id,
            'NCR.product_id must match the inspection product',
        );

        // Marked as auto-generated.
        $this->assertTrue(
            $ncr->is_auto_generated,
            'NCR from inspection failure must be flagged is_auto_generated=true',
        );

        // Description mentions the inspection number.
        $this->assertStringContainsString(
            $insp->inspection_number,
            $ncr->defect_description,
            'NCR description must reference the source inspection number',
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Test 2: Passed inspection creates NO NCR
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * A passing inspection must NEVER auto-create an NCR.
     *
     * Since afterCommit is suppressed under RefreshDatabase, calling
     * complete() on a passing inspection cannot accidentally create an NCR
     * through the callback path either. Both code paths are safe.
     */
    public function test_passed_inspection_creates_no_ncr(): void
    {
        $insp = $this->makePassedInspectionForComplete();

        $this->inspSvc->complete($insp, $this->user);

        // Re-fetch to get fresh status.
        $insp->refresh();

        $this->assertSame(
            InspectionStatus::Passed->value,
            $insp->status->value,
            'Inspection with all passing measurements must resolve to Passed',
        );

        $this->assertSame(
            0,
            NonConformanceReport::where('inspection_id', $insp->id)->count(),
            'A passed inspection must NOT create any NCR',
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Test 3: complete() marks inspection as failed (critical param)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Pins InspectionService::complete() decision logic:
     * any critical-parameter failure → status = failed.
     *
     * This is independent of the afterCommit NCR side-effect.
     */
    public function test_complete_marks_inspection_failed_when_critical_param_fails(): void
    {
        $insp = $this->makeFailedViaComplete();

        $result = $this->inspSvc->complete($insp, $this->user);

        $this->assertSame(
            InspectionStatus::Failed->value,
            $result->status->value,
            'complete() must set status=failed when a critical parameter measurement fails',
        );

        // defect_count updated.
        $this->assertSame(
            1,
            $result->defect_count,
            'defect_count must reflect the number of failing measurement rows',
        );

        // completed_at stamped.
        $this->assertNotNull(
            $result->completed_at,
            'complete() must stamp completed_at',
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Test 4: NCR severity = critical when a critical parameter fails
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * NcrService::openFromInspectionFailure() derives severity from the
     * measurement data:
     *   critical measurement fails → NcrSeverity::Critical
     */
    public function test_ncr_severity_is_critical_when_critical_param_fails(): void
    {
        $insp = $this->makeFailedInspection(
            criticalFail: true,  // is_critical=true measurement with is_pass=false
            defectCount: 1,
            acceptCount: 2,      // defect < accept, so only criticalFail drives severity
        );

        $ncr = $this->ncrSvc->openFromInspectionFailure($insp, $this->user);

        $this->assertSame(
            NcrSeverity::Critical->value,
            $ncr->severity->value,
            'NCR severity must be critical when a critical measurement fails',
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Test 5: NCR severity = high when defect_count > accept_count (no crit)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Non-critical parameters + defect_count > accept_count → severity High.
     * The measurement has is_critical=false so criticalFail=false.
     */
    public function test_ncr_severity_is_high_when_defect_count_exceeds_accept_count(): void
    {
        // Build inspection with is_critical=false measurement failing.
        $insp = Inspection::create([
            'inspection_number'  => 'QC-SEV-' . uniqid(),
            'stage'              => InspectionStage::Outgoing->value,
            'status'             => InspectionStatus::Failed->value,
            'product_id'         => $this->product->id,
            'inspection_spec_id' => $this->spec->id,
            'batch_quantity'     => 100,
            'sample_size'        => 13,
            'aql_code'           => 'G',
            'accept_count'       => 2,
            'reject_count'       => 3,
            'defect_count'       => 5,  // 5 > 2 (accept_count)
            'inspector_id'       => $this->user->id,
            'started_at'         => now(),
            'completed_at'       => now(),
        ]);

        // Non-critical failing measurement.
        InspectionMeasurement::create([
            'inspection_id'           => $insp->id,
            'inspection_spec_item_id' => $this->criticalItem->id,
            'sample_index'            => 1,
            'parameter_name'          => 'Visual check',
            'parameter_type'          => 'visual',
            'unit_of_measure'         => null,
            'nominal_value'           => null,
            'tolerance_min'           => null,
            'tolerance_max'           => null,
            'measured_value'          => null,
            'is_critical'             => false,  // <-- NOT critical
            'is_pass'                 => false,
        ]);

        $insp = $insp->fresh(['measurements']);

        $ncr = $this->ncrSvc->openFromInspectionFailure($insp, $this->user);

        $this->assertSame(
            NcrSeverity::High->value,
            $ncr->severity->value,
            'NCR severity must be high when defect_count > accept_count and no critical fail',
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Test 6: openFromInspectionFailure() is idempotent
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Calling openFromInspectionFailure() twice on the same inspection must
     * NOT create a second NCR — it returns the existing one.
     */
    public function test_open_from_inspection_failure_is_idempotent(): void
    {
        $insp = $this->makeFailedInspection();

        $ncr1 = $this->ncrSvc->openFromInspectionFailure($insp, $this->user);
        $ncr2 = $this->ncrSvc->openFromInspectionFailure($insp, $this->user);

        $this->assertSame(
            1,
            NonConformanceReport::where('inspection_id', $insp->id)->count(),
            'Second call must NOT create a duplicate NCR',
        );

        $this->assertSame(
            $ncr1->id,
            $ncr2->id,
            'Both calls must return the same NCR record',
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Test 7: Rework disposition does NOT auto-create a Work Order
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * According to NcrDisposition::Rework doc-comment: "repair to spec" — no
     * automation. Only NcrDisposition::Scrap triggers replacement WO creation
     * (and only when the linked inspection is outgoing-stage).
     *
     * This pins that close() with rework on an outgoing NCR leaves
     * replacement_work_order_id = null.
     */
    public function test_rework_disposition_does_not_auto_create_work_order(): void
    {
        $insp = $this->makeFailedInspection(stage: InspectionStage::Outgoing);
        $ncr  = $this->ncrSvc->openFromInspectionFailure($insp, $this->user);

        // Set disposition to rework.
        $this->ncrSvc->setDisposition($ncr, NcrDisposition::Rework->value, 'Tool wear', 'Regrind tool');

        // Close the NCR.
        $ncr->refresh();
        $closed = $this->ncrSvc->close($ncr, $this->user);

        $this->assertSame(
            NcrStatus::Closed->value,
            $closed->status->value,
            'NCR must be closed after close()',
        );

        $this->assertNull(
            $closed->replacement_work_order_id,
            'Rework disposition must NOT auto-create a replacement Work Order',
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Test 8: Scrap disposition on outgoing NCR auto-creates replacement WO
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * NcrService::close() with disposition=scrap on an outgoing-inspection
     * NCR calls WorkOrderService::createDraft() and back-fills
     * replacement_work_order_id.
     *
     * WorkOrderService depends on Production module fixtures (machine, mold,
     * BOM) that are expensive to set up here. We verify the linkage by
     * checking that replacement_work_order_id is populated, OR we note that
     * WorkOrderService::createDraft() fails gracefully (it's guarded by the
     * lazy workOrderService() resolver).
     *
     * Actual behavior (locked): if WorkOrderService resolves and createDraft()
     * succeeds, replacement_work_order_id is non-null. If the ProductionModule
     * is absent (null resolver), replacement_work_order_id stays null and the
     * NCR is still closed correctly.
     */
    public function test_scrap_disposition_on_outgoing_ncr_auto_creates_replacement_wo(): void
    {
        $insp = $this->makeFailedInspection(stage: InspectionStage::Outgoing, batchQty: 50);
        $ncr  = $this->ncrSvc->openFromInspectionFailure($insp, $this->user);

        $this->ncrSvc->setDisposition($ncr, NcrDisposition::Scrap->value, 'Over-shrinkage', 'Adjust mold temp');

        $ncr->refresh();
        $closed = $this->ncrSvc->close($ncr, $this->user);

        // NCR must always close cleanly.
        $this->assertSame(
            NcrStatus::Closed->value,
            $closed->status->value,
            'Scrap disposition must still close the NCR cleanly',
        );

        // Resolve the Production module WO service to know if WO auto-creation
        // is expected in this test environment.
        $wos = null;
        try {
            $wos = app(\App\Modules\Production\Services\WorkOrderService::class);
        } catch (\Throwable) {}

        if ($wos !== null) {
            // WorkOrderService available → replacement WO must be created.
            $this->assertNotNull(
                $closed->replacement_work_order_id,
                'Scrap on outgoing NCR must auto-create a replacement WO when Production module is available',
            );

            // Verify WO links back to NCR via parent_ncr_id.
            $wo = \App\Modules\Production\Models\WorkOrder::find($closed->replacement_work_order_id);
            $this->assertNotNull($wo, 'Replacement WorkOrder row must exist in DB');
            $this->assertSame(
                $ncr->id,
                $wo->parent_ncr_id,
                'Replacement WO.parent_ncr_id must point back to the NCR',
            );
            $this->assertSame(
                (int) $insp->batch_quantity,
                (int) $wo->quantity_target,
                'Replacement WO quantity_target must equal NCR.affected_quantity (= batch_quantity)',
            );
        } else {
            // WorkOrderService not resolvable → replacement_work_order_id stays null.
            // This is the expected graceful fallback.
            $this->assertNull(
                $closed->replacement_work_order_id,
                'When Production module WO service is absent, replacement_work_order_id must stay null',
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Test 9: afterCommit gap — NCR not created when complete() called in test
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * REVISED FINDING: afterCommit callbacks DO fire under RefreshDatabase
     * with SQLite in-memory + Laravel's DatabaseTransactionsManager.
     *
     * When InspectionService::complete() is called inside a RefreshDatabase
     * test, the DB::transaction() block creates a savepoint (level 2). On
     * savepoint commit (level drops to 1), Laravel's DatabaseTransactionsManager
     * fires afterCommit callbacks immediately because the transaction manager
     * records the pending callbacks and executes them when the savepoint commits.
     *
     * Consequence: the full NCR auto-creation path (inspection fails →
     * afterCommit → NcrService::openFromInspectionFailure()) WORKS correctly
     * end-to-end in tests too. There is NO silent traceability gap.
     *
     * This test pins that real end-to-end behavior: calling complete() on a
     * failing inspection via InspectionService creates an NCR row automatically.
     */
    public function test_complete_on_failing_inspection_auto_creates_ncr_via_aftercommit(): void
    {
        $insp = $this->makeFailedViaComplete();

        // complete() sets status=failed AND fires afterCommit → NcrService.
        $result = $this->inspSvc->complete($insp, $this->user);

        // Inspection transitions to failed.
        $this->assertSame(
            InspectionStatus::Failed->value,
            $result->status->value,
            'complete() must set status=failed when a critical measurement fails',
        );

        // NCR IS created automatically via the afterCommit callback.
        // afterCommit fires on savepoint commit under RefreshDatabase in SQLite.
        $ncrCount = NonConformanceReport::where('inspection_id', $insp->id)->count();

        $this->assertSame(
            1,
            $ncrCount,
            'complete() on a failing inspection MUST auto-create exactly one NCR ' .
            'via DB::afterCommit → NcrService::openFromInspectionFailure(). ' .
            'The full traceability loop works end-to-end in the test environment.',
        );

        // Verify the auto-created NCR has correct linkage.
        $ncr = NonConformanceReport::where('inspection_id', $insp->id)->first();
        $this->assertSame($insp->id, $ncr->inspection_id);
        $this->assertSame(NcrSource::InspectionFail->value, $ncr->source->value);
        $this->assertTrue($ncr->is_auto_generated);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Test 10: Incoming-stage failed inspection also creates NCR
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * The NCR auto-creation path is stage-agnostic (openFromInspectionFailure
     * does not filter by stage). An incoming-QC failure must also open an NCR.
     */
    public function test_failed_incoming_inspection_also_creates_ncr(): void
    {
        $insp = $this->makeFailedInspection(
            criticalFail: false,
            stage:        InspectionStage::Incoming,
            batchQty:     200,
            defectCount:  10,
            acceptCount:  3,
        );

        $ncr = $this->ncrSvc->openFromInspectionFailure($insp, $this->user);

        $this->assertSame(
            1,
            NonConformanceReport::where('inspection_id', $insp->id)->count(),
            'Incoming-stage failure must also create an NCR',
        );

        $this->assertSame(
            $insp->id,
            $ncr->inspection_id,
        );

        // Stage mention in description.
        $this->assertStringContainsString(
            'incoming',
            $ncr->defect_description,
            'NCR description must mention the inspection stage',
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Test 11: ncr_number follows QC document sequence format
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * NCR numbers must follow the DocumentSequenceService pattern:
     *   NCR-{YYYYMM}-{NNNN}
     */
    public function test_auto_generated_ncr_has_correct_number_format(): void
    {
        $insp = $this->makeFailedInspection();
        $ncr  = $this->ncrSvc->openFromInspectionFailure($insp, $this->user);

        $this->assertMatchesRegularExpression(
            '/^NCR-\d{6}-\d{4}$/',
            $ncr->ncr_number,
            'NCR number must follow the NCR-YYYYMM-NNNN pattern',
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Test 12: complete() blocks on unresolved (is_pass=null) measurements
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * complete() must throw RuntimeException when any measurement row still
     * has is_pass=null. The inspection should not be finalisable until all
     * samples are evaluated.
     */
    public function test_complete_throws_when_measurements_are_unresolved(): void
    {
        $insp = Inspection::create([
            'inspection_number'  => 'QC-BLOK-' . uniqid(),
            'stage'              => InspectionStage::Outgoing->value,
            'status'             => InspectionStatus::Draft->value,
            'product_id'         => $this->product->id,
            'inspection_spec_id' => $this->spec->id,
            'batch_quantity'     => 10,
            'sample_size'        => 2,
            'aql_code'           => null,
            'accept_count'       => 0,
            'reject_count'       => 1,
            'defect_count'       => 0,
            'inspector_id'       => $this->user->id,
            'started_at'         => now(),
        ]);

        // Measurement with is_pass=null (not yet evaluated).
        InspectionMeasurement::create([
            'inspection_id'           => $insp->id,
            'inspection_spec_item_id' => $this->criticalItem->id,
            'sample_index'            => 1,
            'parameter_name'          => 'Shaft OD',
            'parameter_type'          => 'dimensional',
            'unit_of_measure'         => 'mm',
            'nominal_value'           => '10.00',
            'tolerance_min'           => '9.90',
            'tolerance_max'           => '10.10',
            'measured_value'          => null,  // not recorded yet
            'is_critical'             => true,
            'is_pass'                 => null,  // unresolved
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unresolved|no pass\/fail/i');

        $this->inspSvc->complete($insp, $this->user);
    }
}

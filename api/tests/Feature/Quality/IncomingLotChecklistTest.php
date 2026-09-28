<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Enums\InspectionMode;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Exceptions\InspectionCertificateException;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Models\ItemQualityPlan;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\CoCService;
use App\Modules\Quality\Services\InspectionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint X — Incoming QC lot-checklist mode.
 *
 * For large incoming material lots (e.g. 1000 pcs), scaffold a checklist
 * of visual acceptance criteria + a fixed number of dimensional measurements,
 * not a full sample_size matrix.
 *
 * Test strategy
 * ─────────────
 * 1. test_incoming_no_plan_creates_lot_checklist_inspection
 *    – GRN line of 1000 pcs, no quality plan → lot_checklist inspection
 *    with sample_size 80 (AQL), aql_code 'J', accept 1, and 5 measurement rows
 *    (the default checklist), not 80.
 *
 * 2. test_incoming_with_plan_creates_checklist_plus_piece_rows
 *    – Quality plan with 2 no-tolerance params (visual/functional) + 1
 *    toleranced param → 2 checklist rows + 5 piece rows (measured_pieces setting).
 *
 * 3. test_record_lot_result_passes_all_ticked_and_defects_zero
 *    – POST /api/v1/quality/inspections/{id}/lot-result with all checklist ticked,
 *    0 defects, complete=true → passed, GRN accepted.
 *
 * 4. test_record_lot_result_fails_when_defects_exceed_accept
 *    – sample_defect_count 2 (> Ac 1) → failed, NCR opened with defect
 *    count mentioned in description.
 *
 * 5. test_record_lot_result_fails_on_critical_checklist_item
 *    – Critical checklist item marked is_pass=false → failed, even with
 *    0 defects and all other items passing.
 *
 * 6. test_record_lot_result_passes_noncritical_checklist_with_zero_defects
 *    – Only non-critical checklist items NG, sample_defect_count 0 → passed.
 *
 * 7. test_record_lot_result_requires_sample_defect_count
 *    – complete=true without sample_defect_count → 422.
 *
 * 8. test_record_lot_result_rejects_defect_count_exceeding_sample_size
 *    – sample_defect_count > sample_size → 422.
 *
 * 9. test_record_lot_result_rejects_foreign_measurement_id
 *    – measurement id from a different inspection → 422.
 *
 * 10. test_record_lot_result_requires_quality_permission
 *     – Missing quality.inspections.manage → 403.
 *
 * 11. test_record_lot_result_critical_toleranced_measurement_fails
 *     – Critical dimensional parameter out of tolerance → failed.
 *
 * 12. test_receive_with_qc_single_screen_works_on_lot_checklist
 *     – GrnService::receiveWithQc() → GrnService::fastCompleteInspection()
 *     sets sample_defect_count = 0 on lot_checklist before complete().
 *
 * 13. test_measured_pieces_* – the piece-row count comes from the
 *     stage-agnostic quality.inspection.measured_pieces setting, falling back
 *     to the legacy quality.incoming.measured_pieces, then to 5.
 */
class IncomingLotChecklistTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $checker;
    private Item $item;
    private GrnService $grnSvc;
    private InspectionService $inspSvc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $role = Role::firstOrCreate(['slug' => 'qc_inspector'], ['name' => 'QC Inspector']);
        $this->user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $this->checker = User::factory()->create(['is_active' => true]);

        $this->item = Item::factory()->create(['is_active' => true]);
        $this->grnSvc = app(GrnService::class);
        $this->inspSvc = app(InspectionService::class);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function createGrnWith(Item $item, int $quantity = 1000): GoodsReceiptNote
    {
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'created_by' => $this->user->id,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Material',
            'quantity' => (string) $quantity.'.000',
            'unit' => 'pcs',
            'unit_price' => '10.00',
            'total' => (string) ($quantity * 10).'.00',
            'quantity_received' => '0.000',
        ]);
        $location = WarehouseLocation::factory()->create();

        return $this->grnSvc->create($po, [[
            'purchase_order_item_id' => $poItem->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_received' => (string) $quantity.'.000',
            'unit_cost' => '10.00',
        ]], ['received_date' => now()->toDateString()], $this->user);
    }

    private function review(Inspection $inspection, string $decision, string $remarks = ''): Inspection
    {
        return $this->inspSvc->review($inspection->fresh(), $decision, $remarks !== '' ? $remarks : null, $this->checker);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Tests: Creation
    // ──────────────────────────────────────────────────────────────────────────

    public function test_incoming_no_plan_creates_lot_checklist_inspection(): void
    {
        $grn = $this->createGrnWith($this->item, 1000);
        $inspection = Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->firstOrFail();

        $this->assertSame(InspectionMode::LotChecklist, $inspection->inspection_mode);
        $this->assertSame(80, $inspection->sample_size, 'AQL sample for 1000 units should be 80');
        $this->assertSame('J', $inspection->aql_code);
        $this->assertSame(1, $inspection->accept_count);

        // Should have 5 checklist rows (default measured_pieces), not 80.
        $rows = InspectionMeasurement::query()
            ->where('inspection_id', $inspection->id)
            ->get();
        $this->assertSame(5, $rows->count());
        // All should be from the default checklist (no tolerance).
        $this->assertTrue($rows->every(fn ($r) => $r->tolerance_min === null && $r->tolerance_max === null));
    }

    public function test_incoming_with_plan_creates_checklist_plus_piece_rows(): void
    {
        $plan = ItemQualityPlan::query()->create([
            'item_id' => $this->item->id,
            'vendor_id' => null,
            'version' => 1,
            'stage' => 'incoming',
            'sampling_method' => 'aql',
            'is_active' => true,
            'effective_from' => now()->toDateString(),
            'created_by' => $this->user->id,
            'created_by' => $this->user->id,
            'parameters' => [
                [
                    'parameter_name' => 'Packaging condition',
                    'parameter_type' => 'visual',
                    'is_critical' => true,
                    'notes' => 'Check seal integrity',
                ],
                [
                    'parameter_name' => 'Color shade',
                    'parameter_type' => 'visual',
                    'is_critical' => false,
                    'notes' => null,
                ],
                [
                    'parameter_name' => 'Diameter',
                    'parameter_type' => 'dimensional',
                    'unit_of_measure' => 'mm',
                    'nominal_value' => '10.00',
                    'tolerance_min' => '9.90',
                    'tolerance_max' => '10.10',
                    'is_critical' => true,
                ],
            ],
        ]);

        $grn = $this->createGrnWith($this->item);
        $grnItem = $grn->items()->first();
        Inspection::query()->where('grn_item_id', $grnItem->id)->delete();

        $inspection = $this->inspSvc->createIncomingFromPlan($plan, $grnItem, $grn, $this->user);

        $this->assertSame(InspectionMode::LotChecklist, $inspection->inspection_mode);

        // Should have 2 checklist rows (no tolerance) + 5 piece rows (toleranced param × measured_pieces).
        $rows = $inspection->measurements;
        $this->assertSame(7, $rows->count());

        $checklist = $rows->filter(fn ($r) => $r->tolerance_min === null && $r->tolerance_max === null);
        $pieces = $rows->filter(fn ($r) => $r->tolerance_min !== null);
        $this->assertSame(2, $checklist->count());
        $this->assertSame(5, $pieces->count());
        // Piece rows should have sample_index 1..5.
        $this->assertSame([1, 2, 3, 4, 5], $pieces->pluck('sample_index')->unique()->sort()->values()->all());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Tests: Record lot result
    // ──────────────────────────────────────────────────────────────────────────

    public function test_record_lot_result_passes_all_ticked_and_defects_zero(): void
    {
        $grn = $this->createGrnWith($this->item, 1000);
        $inspection = Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->firstOrFail();

        $rows = $inspection->measurements;

        $this->actingAs($this->user)
            ->postJson("/api/v1/quality/inspections/{$inspection->hash_id}/lot-result", [
                'checklist' => $rows->map(fn ($r) => [
                    'id' => $r->hash_id,
                    'is_pass' => true,
                ])->values()->all(),
                'measurements' => [],
                'sample_defect_count' => 0,
                'complete' => true,
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.status', InspectionStatus::AwaitingReview->value);

        $this->review($inspection, InspectionStatus::Passed->value);

        // GRN should be accepted.
        $this->assertSame(GrnStatus::Accepted, $grn->fresh()->status);
    }

    public function test_record_lot_result_fails_when_defects_exceed_accept(): void
    {
        $grn = $this->createGrnWith($this->item, 1000);
        $inspection = Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->firstOrFail();

        $rows = $inspection->measurements;

        $this->actingAs($this->user)
            ->postJson("/api/v1/quality/inspections/{$inspection->hash_id}/lot-result", [
                'checklist' => $rows->map(fn ($r) => [
                    'id' => $r->hash_id,
                    'is_pass' => true,
                ])->values()->all(),
                'measurements' => [],
                'sample_defect_count' => 2, // Exceeds accept_count of 1
                'complete' => true,
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.status', InspectionStatus::AwaitingReview->value);

        $this->review($inspection, InspectionStatus::Failed->value, 'Defective sample confirmed.');

        // NCR should be created with defect count in description.
        $ncr = NonConformanceReport::query()
            ->where('inspection_id', $inspection->id)
            ->firstOrFail();
        $this->assertStringContainsString('defective piece', $ncr->defect_description);
        $this->assertStringContainsString('2', $ncr->defect_description);

        // The failed lot waits for the MRB disposition instead of being
        // rejected outright (IncomingMrbDispositionTest covers the decisions).
        $this->assertSame(GrnStatus::PendingQc, $grn->fresh()->status);
    }

    public function test_record_lot_result_fails_on_critical_checklist_item(): void
    {
        $grn = $this->createGrnWith($this->item, 1000);
        $inspection = Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->firstOrFail();

        $rows = $inspection->measurements;
        $criticalRow = $rows->first();
        $this->assertTrue($criticalRow->is_critical);

        $this->actingAs($this->user)
            ->postJson("/api/v1/quality/inspections/{$inspection->hash_id}/lot-result", [
                'checklist' => $rows->map(fn ($r) => [
                    'id' => $r->hash_id,
                    'is_pass' => $r->id === $criticalRow->id ? false : true,
                ])->values()->all(),
                'measurements' => [],
                'sample_defect_count' => 0,
                'complete' => true,
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.status', InspectionStatus::AwaitingReview->value);

        $this->review($inspection, InspectionStatus::Failed->value, 'Critical checklist failure confirmed.');
    }

    public function test_record_lot_result_passes_noncritical_checklist_with_zero_defects(): void
    {
        $grn = $this->createGrnWith($this->item, 1000);
        $inspection = Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->firstOrFail();

        $rows = $inspection->measurements;
        // Mark only non-critical items as pass (or fail only non-critical).
        $nonCritical = $rows->filter(fn ($r) => ! $r->is_critical)->first();

        $this->actingAs($this->user)
            ->postJson("/api/v1/quality/inspections/{$inspection->hash_id}/lot-result", [
                'checklist' => $rows->map(fn ($r) => [
                    'id' => $r->hash_id,
                    'is_pass' => $r->id === $nonCritical->id ? false : true,
                ])->values()->all(),
                'measurements' => [],
                'sample_defect_count' => 0,
                'complete' => true,
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.status', InspectionStatus::AwaitingReview->value);

        $this->review($inspection, InspectionStatus::Passed->value);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Tests: Validation
    // ──────────────────────────────────────────────────────────────────────────

    public function test_record_lot_result_requires_sample_defect_count(): void
    {
        $grn = $this->createGrnWith($this->item);
        $inspection = Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->firstOrFail();

        $rows = $inspection->measurements;

        $this->actingAs($this->user)
            ->postJson("/api/v1/quality/inspections/{$inspection->hash_id}/lot-result", [
                'checklist' => $rows->map(fn ($r) => [
                    'id' => $r->hash_id,
                    'is_pass' => true,
                ])->values()->all(),
                'measurements' => [],
                'complete' => true,
                // sample_defect_count missing
            ])
            ->assertUnprocessable();
    }

    public function test_record_lot_result_rejects_defect_count_exceeding_sample_size(): void
    {
        $grn = $this->createGrnWith($this->item);
        $inspection = Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->firstOrFail();

        $rows = $inspection->measurements;

        $this->actingAs($this->user)
            ->postJson("/api/v1/quality/inspections/{$inspection->hash_id}/lot-result", [
                'checklist' => $rows->map(fn ($r) => [
                    'id' => $r->hash_id,
                    'is_pass' => true,
                ])->values()->all(),
                'measurements' => [],
                'sample_defect_count' => 999, // Exceeds sample_size
                'complete' => true,
            ])
            ->assertUnprocessable();
    }

    public function test_record_lot_result_rejects_foreign_measurement_id(): void
    {
        $grn = $this->createGrnWith($this->item);
        $inspection = Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->firstOrFail();

        $this->actingAs($this->user)
            ->postJson("/api/v1/quality/inspections/{$inspection->hash_id}/lot-result", [
                'checklist' => [
                    ['id' => 'fake-hash-id', 'is_pass' => true],
                ],
                'measurements' => [],
                'sample_defect_count' => 0,
                'complete' => true,
            ])
            ->assertUnprocessable();
    }

    public function test_record_lot_result_requires_quality_permission(): void
    {
        $grn = $this->createGrnWith($this->item);
        $inspection = Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->firstOrFail();

        $roleWithoutPermission = Role::query()->firstOrCreate(['slug' => 'warehouse_staff'], ['name' => 'Warehouse Staff']);
        $userWithoutPermission = User::factory()->create(['role_id' => $roleWithoutPermission->id, 'is_active' => true]);

        $rows = $inspection->measurements;

        $this->actingAs($userWithoutPermission)
            ->postJson("/api/v1/quality/inspections/{$inspection->hash_id}/lot-result", [
                'checklist' => $rows->map(fn ($r) => [
                    'id' => $r->hash_id,
                    'is_pass' => true,
                ])->values()->all(),
                'measurements' => [],
                'sample_defect_count' => 0,
                'complete' => true,
            ])
            ->assertForbidden();
    }

    public function test_record_lot_result_critical_toleranced_measurement_fails(): void
    {
        $plan = ItemQualityPlan::query()->create([
            'item_id' => $this->item->id,
            'vendor_id' => null,
            'version' => 1,
            'stage' => 'incoming',
            'sampling_method' => 'aql',
            'is_active' => true,
            'effective_from' => now()->toDateString(),
            'created_by' => $this->user->id,
            'created_by' => $this->user->id,
            'parameters' => [
                [
                    'parameter_name' => 'Packaging',
                    'parameter_type' => 'visual',
                    'is_critical' => true,
                ],
                [
                    'parameter_name' => 'Diameter',
                    'parameter_type' => 'dimensional',
                    'unit_of_measure' => 'mm',
                    'nominal_value' => '10.00',
                    'tolerance_min' => '9.90',
                    'tolerance_max' => '10.10',
                    'is_critical' => true,
                ],
            ],
        ]);

        $grn = $this->createGrnWith($this->item);
        $grnItem = $grn->items()->first();
        Inspection::query()->where('grn_item_id', $grnItem->id)->delete();
        $inspection = $this->inspSvc->createIncomingFromPlan($plan, $grnItem, $grn, $this->user);

        $rows = $inspection->measurements;
        $checklist = $rows->filter(fn ($r) => $r->tolerance_min === null);
        $pieces = $rows->filter(fn ($r) => $r->tolerance_min !== null)->values();

        $this->actingAs($this->user)
            ->postJson("/api/v1/quality/inspections/{$inspection->hash_id}/lot-result", [
                'checklist' => $checklist->map(fn ($r) => [
                    'id' => $r->hash_id,
                    'is_pass' => true,
                ])->values()->all(),
                'measurements' => $pieces->map(fn ($row, $index) => [
                    'id' => $row->hash_id,
                    'measured_value' => $index === 0 ? '8.50' : '10.00',
                ])->all(),
                'sample_defect_count' => 0,
                'complete' => true,
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.status', InspectionStatus::AwaitingReview->value);

        $this->review($inspection, InspectionStatus::Failed->value, 'Critical dimensional failure confirmed.');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Tests: one-sided tolerance windows
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * A functional parameter with a nominal must carry at least ONE tolerance
     * bound (UpsertInspectionSpecRequest), so a one-sided window is a shape the
     * spec form compels a user to produce. `InspectionMeasurement::hasTolerance`
     * and `evaluate()` both treat either bound as a window, and the per-unit
     * scaffold copies both bounds verbatim. The lot-checklist scaffold demanded
     * BOTH and so wrote the supplied bound as null — the row lost the very
     * number the user was required to give, became untoleranced, and (being
     * untoleranced) was certifiable with no reading forever after.
     */
    public function test_a_one_bound_functional_parameter_keeps_the_bound_the_user_had_to_supply(): void
    {
        $inspection = $this->inspectionWithOneOneBoundFunctionalParameter();

        $rows = $inspection->measurements;
        $this->assertNotEmpty($rows, 'Precondition: the scaffold produced rows for the parameter.');

        $row = $rows->first();
        $this->assertTrue($row->is_critical, 'Precondition: the parameter is critical.');
        $this->assertNotNull($row->nominal_value, 'Precondition: the parameter carries a nominal.');
        $this->assertNull($row->tolerance_max, 'Precondition: the window is one-sided — no upper bound was supplied.');
        $this->assertSame(
            '90.0000',
            (string) $row->tolerance_min,
            'The bound the user was required to supply must survive the scaffold.',
        );
        $this->assertTrue(
            $row->hasTolerance(),
            'One bound is a tolerance: that is what InspectionMeasurement::hasTolerance() means by it.',
        );

        // The second-order effect, and it is intended: a parameter with a
        // numeric window is a variable characteristic, so it takes the variable
        // sample the default measured-pieces setting gives it — the same shape
        // a dimensional parameter scaffolds — instead of a single checklist row.
        $this->assertSame(5, $rows->count(), 'A numeric window scaffolds measuredPieces piece rows.');
        $this->assertSame(
            [1, 2, 3, 4, 5],
            $rows->pluck('sample_index')->unique()->sort()->values()->all(),
        );
        $this->assertTrue($rows->every(fn ($r) => $r->tolerance_min !== null));
    }

    /**
     * The consequence of that data loss, stated as the certificate rule: an
     * untoleranced critical row is a legitimate attribute record and certifies
     * with no number — which is exactly why a lost bound made a toleranced
     * characteristic certifiable with no evidence. Once the bound survives, the
     * row is toleranced and the null-reading refusal applies to it.
     *
     * Outgoing rather than incoming because a certificate is only issued for
     * the outgoing stage.
     */
    public function test_a_one_bound_critical_characteristic_cannot_be_certified_without_a_reading(): void
    {
        $inspection = $this->outgoingInspectionWithOneOneBoundCriticalCharacteristic();

        $rows = $inspection->measurements;
        $this->assertNotEmpty($rows, 'Precondition: the scaffold produced rows for the parameter.');
        $this->assertTrue($rows->every(fn ($r) => $r->is_critical), 'Precondition: the parameter is critical.');
        $this->assertTrue(
            $rows->every(fn ($r) => $r->tolerance_min !== null),
            'Precondition: the one supplied bound survived the scaffold.',
        );

        // The capture surface records the sample as defect-free and ticks each
        // row conforming; this is the shape an import or repair script writes.
        foreach ($rows as $row) {
            $row->forceFill(['is_pass' => true])->save();
        }
        $inspection->forceFill([
            'sample_defect_count' => 0,
            'status' => InspectionStatus::Passed->value,
            'completed_at' => now(),
            'reviewed_by' => $this->checker->id,
            'reviewed_at' => now(),
        ])->save();

        $fresh = $inspection->fresh();
        $this->assertSame(
            0,
            $fresh->measurements->whereNotNull('measured_value')->count(),
            'Precondition: not one piece was measured.',
        );

        try {
            app(CoCService::class)->buildBinaryForInspection($fresh);
            $this->fail('A Certificate of Conformance was issued for a critical characteristic with no reading.');
        } catch (InspectionCertificateException $e) {
            $this->assertSame('COC_EVIDENCE_INCOMPLETE', $e->errorCode(), $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Tests: Integration
    // ──────────────────────────────────────────────────────────────────────────

    public function test_receive_with_qc_single_screen_works_on_lot_checklist(): void
    {
        $grn = $this->createGrnWith($this->item);
        $inspection = Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->firstOrFail();

        // Simulate fastCompleteInspection by setting sample_defect_count = 0
        // and marking all rows as pass.
        $rows = $inspection->measurements;
        foreach ($rows as $row) {
            $row->forceFill(['is_pass' => true])->save();
        }

        // Complete should work on lot_checklist with sample_defect_count set.
        $inspection->forceFill(['sample_defect_count' => 0])->save();
        $completed = $this->inspSvc->complete($inspection, $this->user);

        $this->assertSame(InspectionStatus::AwaitingReview, $completed->status);
        $completed = $this->review($completed, InspectionStatus::Passed->value);
        $this->assertSame(InspectionStatus::Passed, $completed->status);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Tests: measured-piece setting
    // ──────────────────────────────────────────────────────────────────────────

    public function test_measured_pieces_comes_from_the_new_stage_agnostic_setting(): void
    {
        $this->setSetting('quality.inspection.measured_pieces', 3);
        $this->setSetting('quality.incoming.measured_pieces', 5);

        $inspection = $this->inspectionWithOneTolerancedParameter();

        $this->assertSame(
            3,
            $inspection->measurements->whereNotNull('tolerance_min')->count(),
            'The stage-agnostic setting must win over the legacy incoming-only key.',
        );
    }

    public function test_measured_pieces_falls_back_to_the_legacy_incoming_key(): void
    {
        $this->setSetting('quality.inspection.measured_pieces', null);
        $this->setSetting('quality.incoming.measured_pieces', 2);

        $inspection = $this->inspectionWithOneTolerancedParameter();

        $this->assertSame(2, $inspection->measurements->whereNotNull('tolerance_min')->count());
    }

    public function test_measured_pieces_falls_back_to_five_when_neither_key_is_set(): void
    {
        $this->setSetting('quality.inspection.measured_pieces', null);
        $this->setSetting('quality.incoming.measured_pieces', null);

        $inspection = $this->inspectionWithOneTolerancedParameter();

        $this->assertSame(5, $inspection->measurements->whereNotNull('tolerance_min')->count());
    }

    /** Writes a settings row directly; null deletes it. */
    private function setSetting(string $key, ?int $value): void
    {
        \Illuminate\Support\Facades\DB::table('settings')->where('key', $key)->delete();

        if ($value !== null) {
            \Illuminate\Support\Facades\DB::table('settings')->insert([
                'key' => $key,
                'value' => json_encode($value),
                'group' => 'quality',
                'label' => $key,
                'description' => 'Test override.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        \Illuminate\Support\Facades\Cache::flush();
    }

    /**
     * A plan with a single toleranced parameter, so the piece-row count is
     * exactly the measured-piece setting.
     */
    private function inspectionWithOneTolerancedParameter(): Inspection
    {
        $plan = ItemQualityPlan::query()->create([
            'item_id' => $this->item->id,
            'vendor_id' => null,
            'version' => 1,
            'stage' => 'incoming',
            'sampling_method' => 'aql',
            'is_active' => true,
            'effective_from' => now()->toDateString(),
            'created_by' => $this->user->id,
            'parameters' => [
                [
                    'parameter_name' => 'Diameter',
                    'parameter_type' => 'dimensional',
                    'unit_of_measure' => 'mm',
                    'nominal_value' => '10.00',
                    'tolerance_min' => '9.90',
                    'tolerance_max' => '10.10',
                    'is_critical' => true,
                ],
            ],
        ]);

        $grn = $this->createGrnWith($this->item, 100);
        $grnItem = $grn->items()->first();
        Inspection::query()->where('grn_item_id', $grnItem->id)->delete();

        return $this->inspSvc->createIncomingFromPlan($plan, $grnItem, $grn, $this->user);
    }

    /**
     * The one-sided window a functional parameter with a nominal is compelled
     * to carry: nominal 100, minimum 90, no maximum.
     */
    private function inspectionWithOneOneBoundFunctionalParameter(): Inspection
    {
        $plan = ItemQualityPlan::query()->create([
            'item_id' => $this->item->id,
            'vendor_id' => null,
            'version' => 1,
            'stage' => 'incoming',
            'sampling_method' => 'aql',
            'is_active' => true,
            'effective_from' => now()->toDateString(),
            'created_by' => $this->user->id,
            'parameters' => [
                [
                    'parameter_name' => 'Sealing force',
                    'parameter_type' => 'functional',
                    'unit_of_measure' => 'N',
                    'nominal_value' => '100.00',
                    'tolerance_min' => '90.00',
                    'is_critical' => true,
                ],
            ],
        ]);

        $grn = $this->createGrnWith($this->item, 100);
        $grnItem = $grn->items()->first();
        Inspection::query()->where('grn_item_id', $grnItem->id)->delete();

        return $this->inspSvc->createIncomingFromPlan($plan, $grnItem, $grn, $this->user);
    }

    /**
     * The same one-sided window on an outgoing inspection, whose scaffold runs
     * through the same `scaffoldLotChecklist()` from the product's spec.
     */
    private function outgoingInspectionWithOneOneBoundCriticalCharacteristic(): Inspection
    {
        $product = Product::create([
            'part_number' => 'ONEB-'.substr(uniqid(), -6),
            'name' => 'One-Bound Bushing',
            'unit_of_measure' => 'pcs',
            'standard_cost' => '10.00',
            'is_active' => true,
        ]);

        $spec = InspectionSpec::create([
            'product_id' => $product->id,
            'version' => 1,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);
        InspectionSpecItem::create([
            'inspection_spec_id' => $spec->id,
            'parameter_name' => 'Sealing force',
            'parameter_type' => 'functional',
            'unit_of_measure' => 'N',
            'nominal_value' => '100.0000',
            'tolerance_min' => '90.0000',
            'tolerance_max' => null,
            'is_critical' => true,
            'sort_order' => 1,
        ]);
        $spec->ensureCurrentRevision();

        $so = SalesOrder::factory()->create();
        $wo = WorkOrder::factory()->create([
            'product_id' => $product->id,
            'sales_order_id' => $so->id,
            'quantity_target' => 10,
        ]);
        $output = WorkOrderOutput::create([
            'work_order_id' => $wo->id,
            'batch_code' => 'CB-'.substr(uniqid(), -6),
            'good_count' => 10,
            'reject_count' => 0,
            'recorded_at' => now(),
            'recorded_by' => $this->user->id,
        ]);

        return $this->inspSvc->create([
            'stage' => InspectionStage::Outgoing->value,
            'product_id' => $product->id,
            'batch_quantity' => 10,
            'work_order_output_id' => $output->id,
        ], $this->user);
    }
}

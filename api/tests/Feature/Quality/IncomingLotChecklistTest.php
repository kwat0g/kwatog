<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Enums\InspectionMode;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Models\ItemQualityPlan;
use App\Modules\Quality\Models\NonConformanceReport;
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
}

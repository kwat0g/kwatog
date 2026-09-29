<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionMode;
use App\Modules\Quality\Enums\InspectionOutcome;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Services\InspectionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A 2,000-piece lot takes an AQL sample of 125. Enumerating 125 measured units
 * per dimension is not how that sample is inspected — it is counted — so
 * outgoing and in-process inspections use lot_checklist mode: a checklist plus
 * a reported defect count, with a few pieces actually measured.
 */
class OutgoingQcLotChecklistTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $reviewer;

    private Product $product;

    private InspectionSpec $spec;

    private InspectionService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $role = Role::firstOrCreate(['slug' => 'qc_inspector'], ['name' => 'QC Inspector']);
        $this->user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        // Outgoing inspections are maker-checker gated, so reaching a terminal
        // verdict needs an independent second person holding the review route.
        $reviewRole = Role::firstOrCreate(['slug' => 'quality_reviewer_test'], ['name' => 'Quality Reviewer Test']);
        $reviewPermission = Permission::firstOrCreate(
            ['slug' => 'quality.inspections.review'],
            ['name' => 'Review inspections', 'module' => 'quality'],
        );
        $reviewRole->permissions()->syncWithoutDetaching([$reviewPermission->id]);
        $this->reviewer = User::factory()->create(['role_id' => $reviewRole->id, 'is_active' => true]);

        $this->product = Product::create([
            'part_number' => 'LOT-'.substr(uniqid(), -6),
            'name' => 'Wiper Bushing',
            'unit_of_measure' => 'pcs',
            'standard_cost' => '2.50',
            'is_active' => true,
        ]);

        $this->spec = InspectionSpec::create([
            'product_id' => $this->product->id,
            'version' => 1,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);
        // One toleranced parameter (piece rows) and one visual parameter
        // (a single checklist row).
        InspectionSpecItem::create([
            'inspection_spec_id' => $this->spec->id,
            'parameter_name' => 'Shaft OD',
            'parameter_type' => 'dimensional',
            'unit_of_measure' => 'mm',
            'nominal_value' => '10.0000',
            'tolerance_min' => '9.9000',
            'tolerance_max' => '10.1000',
            'is_critical' => true,
            'sort_order' => 1,
        ]);
        InspectionSpecItem::create([
            'inspection_spec_id' => $this->spec->id,
            'parameter_name' => 'Flash present',
            'parameter_type' => 'visual',
            'is_critical' => false,
            'sort_order' => 2,
        ]);
        $this->spec->ensureCurrentRevision();

        $this->svc = app(InspectionService::class);
    }

    public function test_outgoing_uses_lot_checklist_with_a_small_measured_set(): void
    {
        $inspection = $this->outgoingInspection(batch: 2000);

        $this->assertSame(InspectionMode::LotChecklist, $inspection->inspection_mode);
        $this->assertGreaterThan(
            100,
            (int) $inspection->sample_size,
            'The AQL sample is still the declared sample.',
        );

        $pieceRows = $inspection->measurements->whereNotNull('tolerance_min');
        $checklistRows = $inspection->measurements->whereNull('tolerance_min');

        $this->assertCount(5, $pieceRows, 'Measured pieces default to five, not the AQL sample size.');
        $this->assertCount(1, $checklistRows, 'The visual parameter becomes one checklist row.');
        $this->assertSame('Flash present', $checklistRows->first()->parameter_name);
        $this->assertSame(
            [1, 2, 3, 4, 5],
            $pieceRows->pluck('sample_index')->unique()->sort()->values()->all(),
        );
    }

    public function test_outgoing_lot_passes_and_fails_on_the_reported_defect_count(): void
    {
        $inspection = $this->outgoingInspection(batch: 2000);
        $accept = (int) $inspection->accept_count;
        $this->assertGreaterThan(0, $accept, 'A 2,000-piece lot must carry a non-zero acceptance number.');

        $this->recordVerdict($inspection, defects: $accept);
        $this->assertNotSame(
            InspectionStatus::Failed,
            $this->checkedVerdict($inspection),
        );

        $failed = $this->outgoingInspection(batch: 2000);
        $this->recordVerdict($failed, defects: (int) $failed->accept_count + 1);
        $this->assertSame(
            InspectionStatus::Failed,
            $this->checkedVerdict($failed),
        );
    }

    public function test_in_process_uses_lot_checklist(): void
    {
        $inspection = $this->svc->create([
            'stage' => InspectionStage::InProcess->value,
            'product_id' => $this->product->id,
            'batch_quantity' => 500,
        ], $this->user);

        $this->assertSame(InspectionMode::LotChecklist, $inspection->inspection_mode);
        $this->assertCount(1, $inspection->measurements->whereNull('tolerance_min'));
        $this->assertCount(5, $inspection->measurements->whereNotNull('tolerance_min'));
    }

    public function test_a_spec_with_only_toleranced_parameters_scaffolds_no_checklist_rows(): void
    {
        InspectionSpecItem::query()->where('parameter_name', 'Flash present')->delete();

        $inspection = $this->outgoingInspection(batch: 2000);

        $this->assertCount(0, $inspection->measurements->whereNull('tolerance_min'));
        $this->assertCount(5, $inspection->measurements->whereNotNull('tolerance_min'));

        // The defect count is then the only lot-level input, and the inspection
        // must still be completable through it.
        $this->recordVerdict($inspection, defects: 0);
        $this->assertNotSame(
            InspectionStatus::Draft,
            $this->checkedVerdict($inspection),
        );
    }

    /**
     * A ticked dimension is evidence. "Inspected, conforming" is a different
     * claim from a measurement, and it is the whole point of the capture panel:
     * an inspector counts pieces, they do not type 30 numbers.
     *
     * This is also the control for the two erasure tests above and below: with
     * no reading on the row, a claim is exactly what a tick must still be able
     * to record.
     */
    public function test_a_non_critical_piece_row_is_resolved_by_an_attribute_claim(): void
    {
        $this->addNonCriticalPieceParameter();
        $inspection = $this->outgoingInspection(batch: 2000);

        $this->svc->recordMeasurements($inspection->fresh(), $this->tickPatch($inspection), $this->user);

        $ticked = $this->pieceRows($inspection, 'Flash height');
        $this->assertCount(5, $ticked);
        $this->assertTrue(
            $ticked->every(fn ($r) => $r->is_pass === true),
            'A tick is recorded as an explicit attribute claim.',
        );
        $this->assertTrue(
            $ticked->every(fn ($r) => $r->measured_value === null),
            'An attribute claim carries no reading.',
        );

        // The claim resolves the row for complete(), which refuses on any
        // measurement left without a pass/fail.
        $inspection->forceFill(['sample_defect_count' => 0])->save();
        $this->assertSame(InspectionStatus::Passed, $this->checkedVerdict($inspection->fresh()));
    }

    /** The same claim reaches the service through the request: C1's end-to-end path. */
    public function test_a_ticked_dimension_survives_the_request_validation(): void
    {
        $this->addNonCriticalPieceParameter();
        $inspection = $this->outgoingInspection(batch: 2000);

        $this->actingAs($this->user)
            ->postJson(
                "/api/v1/quality/inspections/{$inspection->hash_id}/lot-result",
                $this->lotPayload($inspection),
            )
            ->assertSuccessful();

        $ticked = $this->pieceRows($inspection, 'Flash height');
        $this->assertCount(5, $ticked);
        $this->assertTrue($ticked->every(fn ($r) => $r->is_pass === true));
        $this->assertSame(
            InspectionStatus::AwaitingReview,
            $inspection->fresh()->status,
            'The ticked dimension reaches a submittable state instead of throwing on unresolved rows.',
        );
    }

    public function test_an_attribute_ng_claim_counts_as_a_defect(): void
    {
        $this->addNonCriticalPieceParameter();
        $inspection = $this->outgoingInspection(batch: 2000);

        $ng = $this->pieceRows($inspection, 'Flash height')->firstOrFail();

        $this->svc->recordMeasurements(
            $inspection->fresh(),
            [$ng->id => ['measured_value' => null, 'is_pass' => false]],
            $this->user,
        );

        $this->assertFalse($ng->fresh()->is_pass);
        $this->assertSame(1, (int) $inspection->fresh()->defect_count);
    }

    public function test_a_reading_decides_over_a_contradicting_client_claim(): void
    {
        $this->addNonCriticalPieceParameter();
        $inspection = $this->outgoingInspection(batch: 2000);

        $row = $this->pieceRows($inspection, 'Flash height')->firstOrFail();

        // A client asserting a pass over an out-of-tolerance reading cannot
        // write a forged record: the value decides.
        $this->svc->recordMeasurements(
            $inspection->fresh(),
            [$row->id => ['measured_value' => '9.0000', 'is_pass' => true]],
            $this->user,
        );

        $stored = $row->fresh();
        $this->assertFalse($stored->is_pass);
        $this->assertSame('9.0000', (string) $stored->measured_value);
    }

    /**
     * A tick fills a blank row; it never overwrites a recorded reading. The
     * claim arrives in the same request that would delete the value, so the
     * value has to be the authority: otherwise one PATCH turns a recorded
     * failure into a pass and drops the defect count with it.
     */
    public function test_a_claim_cannot_erase_an_out_of_tolerance_reading(): void
    {
        $this->addNonCriticalPieceParameter();
        $inspection = $this->outgoingInspection(batch: 2000);
        $row = $this->pieceRows($inspection, 'Flash height')->firstOrFail();

        // A failing reading, recorded by an earlier successful call.
        $this->svc->recordMeasurements(
            $inspection->fresh(),
            [$row->id => ['measured_value' => '0.9000']],
            $this->user,
        );
        $this->assertFalse($row->fresh()->is_pass);
        $this->assertSame(1, (int) $inspection->fresh()->defect_count);

        // The rewrite: erase the value and assert conformance in one call.
        try {
            $this->svc->recordMeasurements(
                $inspection->fresh(),
                [$row->id => ['measured_value' => null, 'is_pass' => true]],
                $this->user,
            );
            $this->fail('A claim must not overwrite a stored reading.');
        } catch (BusinessRuleException) {
            // Expected: refused as a whole, so the row keeps its evidence.
        }

        $stored = $row->fresh();
        $this->assertSame('0.9000', (string) $stored->measured_value, 'The reading survives.');
        $this->assertFalse($stored->is_pass, 'The failure is still recorded.');
        $this->assertSame(
            1,
            (int) $inspection->fresh()->defect_count,
            'A refused request must not clear the defect it was trying to erase.',
        );
    }

    /** The same rule, the other direction: a claim cannot erase a passing reading. */
    public function test_a_claim_cannot_erase_an_in_tolerance_reading(): void
    {
        $this->addNonCriticalPieceParameter();
        $inspection = $this->outgoingInspection(batch: 2000);
        $row = $this->pieceRows($inspection, 'Flash height')->firstOrFail();

        $this->svc->recordMeasurements(
            $inspection->fresh(),
            [$row->id => ['measured_value' => '0.5000']],
            $this->user,
        );
        $this->assertTrue($row->fresh()->is_pass);

        try {
            $this->svc->recordMeasurements(
                $inspection->fresh(),
                [$row->id => ['measured_value' => null, 'is_pass' => false]],
                $this->user,
            );
            $this->fail('A claim must not overwrite a stored reading.');
        } catch (BusinessRuleException) {
            // Expected.
        }

        $stored = $row->fresh();
        $this->assertSame('0.5000', (string) $stored->measured_value);
        $this->assertTrue($stored->is_pass, 'The conforming reading still decides.');
    }

    /** A CTQ is measured, not asserted: the rejection survives for critical rows. */
    public function test_a_critical_reading_still_refuses_a_contradicting_claim(): void
    {
        $inspection = $this->outgoingInspection(batch: 2000);
        $row = $this->pieceRows($inspection, 'Shaft OD')->firstOrFail();

        $this->expectException(BusinessRuleException::class);

        $this->svc->recordMeasurements(
            $inspection->fresh(),
            [$row->id => ['measured_value' => '10.0000', 'is_pass' => false]],
            $this->user,
        );
    }

    public function test_an_unanswered_row_still_blocks_completion(): void
    {
        $this->addNonCriticalPieceParameter();
        $inspection = $this->outgoingInspection(batch: 2000);

        $unanswered = $this->pieceRows($inspection, 'Flash height')->firstOrFail();

        DB::table('inspection_measurements')
            ->where('inspection_id', $inspection->id)
            ->where('id', '!=', $unanswered->id)
            ->update(['is_pass' => true, 'measured_value' => '10.0000']);

        $inspection->forceFill(['sample_defect_count' => 0])->save();

        $this->assertNull($unanswered->fresh()->is_pass, 'Nothing resolved the row.');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('no pass/fail recorded');

        $this->svc->complete($inspection->fresh(), $this->user);
    }

    /**
     * A one-sided window is still a window. A functional parameter may carry a
     * single bound (`UpsertInspectionSpecRequest` requires only that much), and
     * the scaffold treats it as toleranced — so the recorder must accept the
     * rows the scaffold writes, or the lot cannot be captured at all.
     */
    public function test_a_one_bound_piece_row_is_capturable(): void
    {
        $product = Product::create([
            'part_number' => '1B-'.substr(uniqid(), -6),
            'name' => 'Relay Cover',
            'unit_of_measure' => 'pcs',
            'standard_cost' => '1.00',
            'is_active' => true,
        ]);
        $spec = InspectionSpec::create([
            'product_id' => $product->id,
            'version' => 1,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);
        // Functional, critical, nominal with a lower bound only: `90 … +∞`.
        InspectionSpecItem::create([
            'inspection_spec_id' => $spec->id,
            'parameter_name' => 'Holding pressure',
            'parameter_type' => 'functional',
            'unit_of_measure' => 'bar',
            'nominal_value' => '90.0000',
            'tolerance_min' => '90.0000',
            'tolerance_max' => null,
            'is_critical' => true,
            'sort_order' => 1,
        ]);
        $spec->ensureCurrentRevision();

        // The row the scaffold reads: one bound, and it is the lower one.
        $item = $spec->items->firstOrFail();
        $this->assertSame('90.0000', (string) $item->tolerance_min);
        $this->assertNull($item->tolerance_max);

        $inspection = $this->outgoingInspection(batch: 2000, product: $product);
        $pieceRows = $inspection->measurements->whereNotNull('tolerance_min');

        $this->assertGreaterThan(
            1,
            $pieceRows->count(),
            'A one-bound parameter still scaffolds the measured pieces, not a one-row matrix.',
        );
        $this->assertCount(0, $inspection->measurements->whereNull('tolerance_min'));

        $row = $pieceRows->firstOrFail();
        $this->svc->recordMeasurements(
            $inspection->fresh(),
            [$row->id => ['measured_value' => '95.0000']],
            $this->user,
        );

        $this->assertSame('95.0000', (string) $row->fresh()->measured_value);
        $this->assertTrue($row->fresh()->is_pass, 'The single bound decides the reading.');

        // The lot is capturable end to end, not merely readable through the
        // service: the recording endpoint used to reject these rows outright.
        $fresh = $this->outgoingInspection(batch: 2000, product: $product);
        $rows = $fresh->measurements;

        $this->actingAs($this->user)
            ->postJson(
                "/api/v1/quality/inspections/{$fresh->hash_id}/lot-result",
                [
                    'checklist' => [],
                    'measurements' => $rows
                        ->map(fn ($r) => ['id' => $r->hash_id, 'measured_value' => '95.0000'])
                        ->values()
                        ->all(),
                    'sample_defect_count' => 0,
                    'complete' => true,
                ],
            )
            ->assertSuccessful();

        $this->assertTrue(
            $rows->every(fn ($r) => $r->fresh()->measured_value === '95.0000'),
            'Every scaffolded piece row accepted its reading.',
        );
    }

    /** The payload the capture panel sends when a dimension is ticked. */
    private function lotPayload(Inspection $inspection): array
    {
        $measurements = [];
        foreach ($inspection->measurements as $row) {
            if (! $row->hasTolerance()) {
                continue;
            }
            $measurements[] = $row->is_critical
                // A CTQ keeps its measured reading.
                ? ['id' => $row->hash_id, 'measured_value' => '10.0000', 'is_pass' => null]
                // The ticked dimension: the claim, and no value.
                : ['id' => $row->hash_id, 'measured_value' => null, 'is_pass' => true];
        }

        $checklist = $inspection->measurements
            ->reject(fn ($r) => $r->hasTolerance())
            ->map(fn ($r) => ['id' => $r->hash_id, 'is_pass' => true])
            ->values()
            ->all();

        return [
            'checklist' => $checklist,
            'measurements' => $measurements,
            'sample_defect_count' => 0,
            'complete' => true,
        ];
    }

    /** The same payload as a service-level row map. */
    private function tickPatch(Inspection $inspection): array
    {
        $patch = [];
        foreach ($inspection->measurements as $row) {
            $patch[$row->id] = $row->hasTolerance()
                ? ($row->is_critical
                    ? ['measured_value' => '10.0000', 'is_pass' => null]
                    : ['measured_value' => null, 'is_pass' => true])
                : ['is_pass' => true];
        }

        return $patch;
    }

    /** @return Collection<int, InspectionMeasurement> */
    private function pieceRows(Inspection $inspection, string $parameter): Collection
    {
        return InspectionMeasurement::query()
            ->where('inspection_id', $inspection->id)
            ->where('parameter_name', $parameter)
            ->get();
    }

    /** A non-critical toleranced dimension: the case a tick answers with no reading. */
    private function addNonCriticalPieceParameter(): void
    {
        InspectionSpecItem::create([
            'inspection_spec_id' => $this->spec->id,
            'parameter_name' => 'Flash height',
            'parameter_type' => 'dimensional',
            'unit_of_measure' => 'mm',
            'nominal_value' => '0.5000',
            'tolerance_min' => '0.4000',
            'tolerance_max' => '0.6000',
            'is_critical' => false,
            'sort_order' => 3,
        ]);
        $this->spec->ensureCurrentRevision();
    }

    /**
     * Complete, then check the result.
     *
     * An outgoing inspection is maker-checker gated (Inspection::requiresMakerChecker),
     * so `complete()` alone stops at awaiting_review and the verdict is only
     * terminal once a second person signs it off.
     */
    private function checkedVerdict(Inspection $inspection): InspectionStatus
    {
        $completed = $this->svc->complete($inspection->fresh(), $this->user);

        if ($completed->status !== InspectionStatus::AwaitingReview) {
            return $completed->status;
        }

        $proposed = $completed->proposed_result instanceof InspectionOutcome
            ? $completed->proposed_result->value
            : (string) $completed->proposed_result;

        return $this->svc->review(
            $completed,
            $proposed,
            $proposed === InspectionStatus::Failed->value
                ? 'The reported defect count exceeds the acceptance number.'
                : null,
            $this->reviewer,
        )->status;
    }

    private function outgoingInspection(int $batch, ?Product $product = null): Inspection
    {
        $product ??= $this->product;
        $so = SalesOrder::factory()->create();
        $wo = WorkOrder::factory()->create([
            'product_id' => $product->id,
            'sales_order_id' => $so->id,
            'quantity_target' => $batch,
        ]);
        $output = WorkOrderOutput::create([
            'work_order_id' => $wo->id,
            'batch_code' => 'CB-'.substr(uniqid(), -6),
            'good_count' => $batch,
            'reject_count' => 0,
            'recorded_at' => now(),
            'recorded_by' => $this->user->id,
        ]);

        return $this->svc->create([
            'stage' => InspectionStage::Outgoing->value,
            'product_id' => $product->id,
            'batch_quantity' => $batch,
            'work_order_output_id' => $output->id,
        ], $this->user);
    }

    /** Resolves every row and records the reported count, without completing. */
    private function recordVerdict(Inspection $inspection, int $defects): void
    {
        DB::table('inspection_measurements')
            ->where('inspection_id', $inspection->id)
            ->update(['is_pass' => true, 'measured_value' => '10.0000']);

        $inspection->forceFill(['sample_defect_count' => $defects])->save();
    }
}

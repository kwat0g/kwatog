<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

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
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Services\InspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function outgoingInspection(int $batch): Inspection
    {
        $so = SalesOrder::factory()->create();
        $wo = WorkOrder::factory()->create([
            'product_id' => $this->product->id,
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
            'product_id' => $this->product->id,
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

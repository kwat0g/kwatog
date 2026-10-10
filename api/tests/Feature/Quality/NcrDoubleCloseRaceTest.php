<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Enums\NcrActionType;
use App\Modules\Quality\Enums\NcrDisposition;
use App\Modules\Quality\Enums\NcrStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NcrAction;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\NcrService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Support\HasPassedInProcessQc;

/**
 * NCR closure must re-read terminal status under lock so two callers holding
 * open snapshots cannot close twice or duplicate the linked repair work order.
 */
class NcrDoubleCloseRaceTest extends TestCase
{
    use RefreshDatabase;
    use HasPassedInProcessQc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('quality.ncr.replacement_work_order_priority', 7);
        app(SettingsService::class)->set('quality.ncr.replacement_work_order_lead_days', 1);
    }

    private function user(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function reworkNcr(User $by): NonConformanceReport
    {
        $maker = $this->user();
        $sorter = $this->user();
        $product = Product::factory()->create();
        $original = WorkOrder::factory()->create([
            'product_id' => $product->id,
            'quantity_target' => 100,
            'quantity_good' => 100,
            'quantity_produced' => 100,
            'status' => 'closed',
            'created_by' => $maker->id,
        ]);
        $this->passInProcessQc($original);
        $sourceOutput = WorkOrderOutput::create([
            'work_order_id' => $original->id,
            'recorded_by' => $maker->id,
            'recorded_at' => now(),
            'good_count' => 100,
            'reject_count' => 0,
        ]);
        $insp = Inspection::create([
            'inspection_number' => 'QC-RACE-'.substr(uniqid(), -8),
            'stage' => InspectionStage::Outgoing->value,
            'product_id' => $product->id,
            'entity_type' => 'work_order',
            'entity_id' => $original->id,
            'work_order_output_id' => $sourceOutput->id,
            'batch_quantity' => 100,
            'sample_size' => 5,
            'accept_count' => 0,
            'defect_count' => 5,
            'status' => InspectionStatus::Failed->value,
            'inspector_id' => $maker->id,
            'reviewed_by' => $by->id,
            'reviewed_at' => now(),
        ]);
        $ncr = NonConformanceReport::factory()->create([
            'inspection_id' => $insp->id,
            'product_id' => $product->id,
            'affected_quantity' => 50,
            'created_by' => $maker->id,
        ]);
        $ncr = app(NcrService::class)->setDisposition($ncr, NcrDisposition::Rework->value, 'Sort completed.', null, $by, '60', [
            'inspected_quantity' => '100',
            'evidence_reference' => 'SORT-RACE-100',
            'performed_by' => $sorter->id,
            'recovery_passed' => true,
        ]);
        $repair = WorkOrder::findOrFail($ncr->rework_work_order_id);
        $this->passInProcessQc($repair);
        $repair->forceFill(['status' => 'completed', 'quantity_good' => 40, 'quantity_produced' => 40])->save();
        $repairOutput = WorkOrderOutput::create([
            'work_order_id' => $repair->id,
            'recorded_by' => $maker->id,
            'recorded_at' => now(),
            'good_count' => 40,
            'reject_count' => 0,
        ]);
        Inspection::create([
            'inspection_number' => 'QC-RACE-REPAIR-'.substr(uniqid(), -8),
            'stage' => InspectionStage::Outgoing->value,
            'status' => InspectionStatus::Passed->value,
            'product_id' => $product->id,
            'entity_type' => 'work_order',
            'entity_id' => $repair->id,
            'work_order_output_id' => $repairOutput->id,
            'batch_quantity' => 40,
            'sample_size' => 5,
            'accepted_quantity' => 40,
            'inspector_id' => $maker->id,
            'reviewed_by' => $by->id,
            'reviewed_at' => now(),
        ]);

        foreach ([NcrActionType::Corrective, NcrActionType::Preventive] as $type) {
            NcrAction::create([
                'ncr_id' => $ncr->id,
                'action_type' => $type->value,
                'description' => 'Verified recovery action.',
                'performed_by' => $ncr->created_by,
                'performed_at' => now(),
            ]);
        }

        return $ncr;
    }

    public function test_stale_second_close_is_blocked_and_creates_single_rework_wo(): void
    {
        $by = $this->user();
        $ncr = $this->reworkNcr($by);
        $repairId = $ncr->rework_work_order_id;
        $this->assertNotNull($repairId);

        // Both "concurrent" closers fetched the row while it was open.
        $closerA = NonConformanceReport::find($ncr->id);
        $closerB = NonConformanceReport::find($ncr->id);

        app(NcrService::class)->close($closerA, $by);

        $this->assertSame(
            1,
            WorkOrder::query()->where('parent_ncr_id', $ncr->id)->count(),
            'Exactly one rework WO must be created for the NCR.'
        );

        try {
            app(NcrService::class)->close($closerB, $by);
            $this->fail('A stale second close must be rejected.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('already closed', strtolower($e->getMessage()));
        }

        $this->assertSame(
            1,
            WorkOrder::query()->where('parent_ncr_id', $ncr->id)->count(),
            'The stale close must not create a second rework WO.'
        );
        $this->assertSame(NcrStatus::Closed, $ncr->refresh()->status);
        $this->assertSame($repairId, $ncr->rework_work_order_id);
        $this->assertNull($ncr->replacement_work_order_id);
    }
}

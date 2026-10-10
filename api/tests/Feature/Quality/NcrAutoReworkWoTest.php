<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\BomItem;
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
use App\Modules\Quality\Resources\NcrResource;
use App\Modules\Quality\Services\NcrService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Support\HasPassedInProcessQc;

class NcrAutoReworkWoTest extends TestCase
{
    use RefreshDatabase;
    use HasPassedInProcessQc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('quality.ncr.replacement_work_order_priority', 7);
    }

    private function user(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function ncrFromInspection(User $by, InspectionStage $stage = InspectionStage::Outgoing): NonConformanceReport
    {
        $maker = $this->user();
        $product = Product::factory()->create();
        $wo = WorkOrder::factory()->create([
            'product_id' => $product->id,
            'quantity_target' => 100,
            'quantity_good' => 100,
            'quantity_produced' => 100,
            'status' => 'closed',
            'created_by' => $maker->id,
        ]);
        if ($stage !== InspectionStage::InProcess) {
            $this->passInProcessQc($wo);
        }
        $output = $stage === InspectionStage::Outgoing ? WorkOrderOutput::create([
            'work_order_id' => $wo->id,
            'recorded_by' => $maker->id,
            'recorded_at' => now(),
            'good_count' => 100,
            'reject_count' => 0,
        ]) : null;
        if ($output) {
            $item = Item::factory()->create(['code' => $product->part_number, 'item_type' => 'finished_good']);
            $zone = WarehouseZone::factory()->create(['zone_type' => 'finished_goods']);
            $location = WarehouseLocation::factory()->create(['zone_id' => $zone->id]);
            $receipt = StockMovement::create([
                'movement_type' => 'production_receipt', 'item_id' => $item->id, 'quantity' => '100', 'unit_cost' => '5.00', 'total_cost' => '500.00',
                'to_location_id' => $location->id, 'reference_type' => 'work_order_output', 'reference_id' => $output->id, 'created_by' => $maker->id, 'lot_number' => 'ORIGINAL-'.$output->id,
            ]);
            $output->forceFill(['production_receipt_movement_id' => $receipt->id])->save();
            StockLevel::create(['item_id' => $item->id, 'location_id' => $location->id, 'quantity' => 100, 'reserved_quantity' => 0, 'weighted_avg_cost' => '5.00']);
        }
        $insp = Inspection::create([
            'inspection_number' => 'QC-T-'.substr(uniqid(), -8),
            'stage' => $stage->value,
            'product_id' => $product->id,
            'entity_type' => 'work_order',
            'entity_id' => $wo->id,
            'work_order_output_id' => $output?->id,
            'batch_quantity' => 100,
            'sample_size' => 5,
            'accept_count' => 0,
            'defect_count' => 5,
            'status' => InspectionStatus::Failed->value,
            'inspector_id' => $maker->id,
            'reviewed_by' => $by->id,
            'reviewed_at' => now(),
        ]);

        return NonConformanceReport::factory()->create([
            'inspection_id' => $insp->id,
            'product_id' => $product->id,
            'affected_quantity' => 50,
            'created_by' => $maker->id,
        ]);
    }

    private function sort(NonConformanceReport $ncr, User $by, NcrDisposition $disposition = NcrDisposition::Rework, string $goodQuantity = '60'): NonConformanceReport
    {
        return app(NcrService::class)->setDisposition($ncr, $disposition->value, 'Sort completed.', null, $by, $goodQuantity, [
            'inspected_quantity' => '100',
            'evidence_reference' => 'SORT-100',
            'performed_by' => $this->user()->id,
            'recovery_passed' => true,
        ]);
    }

    private function completeRepair(WorkOrder $repair, User $by): void
    {
        $maker = $this->user();
        $quantity = (int) $repair->quantity_target;
        $this->passInProcessQc($repair);
        $repair->forceFill(['status' => 'completed', 'quantity_good' => $quantity, 'quantity_produced' => $quantity])->save();
        $output = WorkOrderOutput::create([
            'work_order_id' => $repair->id,
            'recorded_by' => $maker->id,
            'recorded_at' => now(),
            'good_count' => $quantity,
            'reject_count' => 0,
        ]);
        Inspection::create([
            'inspection_number' => 'QC-REPAIR-'.substr(uniqid(), -8),
            'stage' => InspectionStage::Outgoing->value,
            'status' => InspectionStatus::Passed->value,
            'product_id' => $repair->product_id,
            'entity_type' => 'work_order',
            'entity_id' => $repair->id,
            'work_order_output_id' => $output->id,
            'batch_quantity' => $quantity,
            'sample_size' => 5,
            'accepted_quantity' => $quantity,
            'inspector_id' => $maker->id,
            'reviewed_by' => $by->id,
            'reviewed_at' => now(),
        ]);
    }

    private function addBothActions(NonConformanceReport $ncr, User $by): void
    {
        foreach ([NcrActionType::Corrective, NcrActionType::Preventive] as $t) {
            NcrAction::create([
                'ncr_id' => $ncr->id, 'action_type' => $t->value,
                'description' => 'Documented recovery action.', 'performed_by' => $by->id, 'performed_at' => now(),
            ]);
        }
    }

    public function test_sorted_outgoing_disposition_creates_repair_without_bom_materials_before_close(): void
    {
        $by = $this->user();
        $ncr = $this->ncrFromInspection($by);
        $material = Item::factory()->create();
        $bom = Bom::create(['product_id' => $ncr->product_id, 'version' => 1, 'is_active' => true]);
        BomItem::create([
            'bom_id' => $bom->id, 'item_id' => $material->id, 'quantity_per_unit' => '1.0000',
            'unit' => $material->unit_of_measure, 'waste_factor' => '0.00', 'sort_order' => 0,
        ]);

        $ncr = $this->sort($ncr, $by);

        $this->assertSame(NcrStatus::InProgress, $ncr->status);
        $this->assertSame(40, (int) $ncr->confirmed_rework_quantity);
        $wo = WorkOrder::findOrFail($ncr->rework_work_order_id);
        $this->assertSame((int) $ncr->id, (int) $wo->parent_ncr_id);
        $this->assertSame((int) $ncr->inspection->work_order_output_id, (int) $wo->rework_source_output_id);
        $this->assertSame(40, (int) $wo->quantity_target);
        $this->assertSame(7, (int) $wo->priority);
        $this->assertSame('service', $wo->work_order_class);
        $this->assertCount(0, $wo->materials);

        $this->completeRepair($wo, $by);
        $this->addBothActions($ncr, $by);
        $closed = app(NcrService::class)->close($ncr, $by);
        $this->assertSame(NcrStatus::Closed, $closed->status);
        $this->assertSame($wo->id, $closed->rework_work_order_id);
        $this->assertSame(1, WorkOrder::where('parent_ncr_id', $ncr->id)->count());
    }

    public function test_rework_disposition_in_process_does_not_create_wo(): void
    {
        $by = $this->user();
        $ncr = $this->sort($this->ncrFromInspection($by, InspectionStage::InProcess), $by, goodQuantity: '100');
        $this->addBothActions($ncr, $by);

        $closed = app(NcrService::class)->close($ncr, $by);

        $this->assertNull($closed->rework_work_order_id);
    }

    public function test_sorted_scrap_creates_replacement_without_repair(): void
    {
        $by = $this->user();
        $ncr = $this->sort($this->ncrFromInspection($by), $by, NcrDisposition::Scrap);
        $this->addBothActions($ncr, $by);

        $closed = app(NcrService::class)->close($ncr, $by);

        $this->assertNull($closed->rework_work_order_id);
        $this->assertNotNull($closed->replacement_work_order_id);
        $this->assertSame(40, (int) WorkOrder::findOrFail($closed->replacement_work_order_id)->quantity_target);
    }

    public function test_rework_work_order_link_is_exposed_by_ncr_detail(): void
    {
        $by = $this->user();
        $ncr = $this->sort($this->ncrFromInspection($by), $by);
        $payload = (new NcrResource(app(NcrService::class)->show($ncr)))->resolve();

        $this->assertSame(
            $ncr->reworkWorkOrder->hash_id,
            $payload['rework_work_order']['id'],
        );
        $this->assertSame(
            $ncr->reworkWorkOrder->wo_number,
            $payload['rework_work_order']['wo_number'],
        );
    }
}

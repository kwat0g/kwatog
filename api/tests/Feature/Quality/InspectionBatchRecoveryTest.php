<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\MovementGlPostingService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\MRP\Services\MrpEngineService;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\NcrService;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Services\DeliveryService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Support\HasPassedInProcessQc;

class InspectionBatchRecoveryTest extends TestCase
{
    use RefreshDatabase;
    use HasPassedInProcessQc;

    private User $maker;

    private User $checker;

    private User $sorter;

    private User $approver;

    private Inspection $inspection;

    private NonConformanceReport $ncr;

    private SalesOrder $salesOrder;

    private SalesOrderItem $salesOrderItem;

    private WorkOrderOutput $output;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed([ChartOfAccountsSeeder::class, SettingsSeeder::class]);
        $this->maker = User::factory()->create(['is_active' => true]);
        $this->checker = User::factory()->create(['is_active' => true]);
        $this->sorter = User::factory()->create(['is_active' => true]);
        $this->approver = User::factory()->create(['is_active' => true]);
        $product = Product::factory()->create(['unit_of_measure' => 'pcs', 'standard_cost' => '4.50']);
        $this->salesOrder = SalesOrder::factory()->create(['status' => 'confirmed', 'created_by' => $this->maker->id]);
        $this->salesOrderItem = SalesOrderItem::factory()->create([
            'sales_order_id' => $this->salesOrder->id, 'product_id' => $product->id,
            'quantity' => '100.00', 'unit_price' => '15.00', 'total' => '1500.00',
            'quantity_delivered' => '0.00',
        ]);
        $workOrder = WorkOrder::factory()->create([
            'product_id' => $product->id, 'sales_order_id' => $this->salesOrder->id,
            'sales_order_item_id' => $this->salesOrderItem->id, 'status' => 'completed',
            'quantity_target' => 100, 'quantity_good' => 100, 'quantity_produced' => 100,
            'created_by' => $this->maker->id,
        ]);
        $this->passInProcessQc($workOrder);
        $this->output = WorkOrderOutput::create([
            'work_order_id' => $workOrder->id, 'recorded_by' => $this->maker->id,
            'recorded_at' => now(), 'good_count' => 100, 'reject_count' => 0,
            'batch_code' => 'RECOVERY-100',
        ]);
        $this->inspection = Inspection::create([
            'inspection_number' => 'QC-RECOVERY-100', 'stage' => 'outgoing', 'status' => 'failed',
            'inspection_mode' => 'lot_checklist', 'inspector_id' => $this->maker->id,
            'reviewed_by' => $this->checker->id, 'reviewed_at' => now(),
            'review_remarks' => 'Confirmed one defective piece in the planned sample.',
            'product_id' => $product->id, 'entity_type' => 'work_order', 'entity_id' => $workOrder->id,
            'work_order_output_id' => $this->output->id, 'batch_quantity' => 100,
            'accepted_quantity' => 0, 'sample_size' => 20, 'accept_count' => 0,
            'reject_count' => 1, 'defect_count' => 1, 'completed_at' => now(),
        ]);
        $this->inspection->forceFill(['sample_defect_count' => 1, 'inspected_quantity' => 20])->save();
        $this->inspection->resultAuthors()->create(['user_id' => $this->maker->id]);
        // Arrange the final failure directly so unrelated NCR/outbox jobs are not part of this boundary.
        $this->ncr = NonConformanceReport::factory()->create([
            'inspection_id' => $this->inspection->id, 'product_id' => $product->id,
            'affected_quantity' => 100, 'created_by' => $this->maker->id,
            'defect_description' => 'One cracked piece found in the twenty-piece sample.',
        ]);
    }

    public function test_failed_sample_holds_the_entire_output_until_independent_sorting_release(): void
    {
        $this->assertTrue($this->inspection->isMakerChecked());
        $this->assertFalse($this->inspection->canReleaseOutgoing());
        $this->assertSame([], app(DeliveryService::class)->inspectionOptions($this->salesOrder->id));
        $before = $this->inspection->fresh()->getRawOriginal();

        $released = $this->recover();

        $this->assertSame(99, $this->inspection->fresh()->accepted_quantity);
        $this->assertTrue($this->inspection->fresh()->canReleaseOutgoing());
        foreach (['status', 'batch_quantity', 'sample_size', 'sample_defect_count', 'inspected_quantity', 'defect_count', 'completed_at', 'reviewed_by', 'reviewed_at'] as $field) {
            $this->assertSame($before[$field], $this->inspection->fresh()->getRawOriginal($field), $field);
        }
        $this->assertSame(100, $released->affected_quantity);
        $this->assertSame('99.000', $released->mrb_accepted_quantity);
        $this->assertSame('100.000', $released->sorting_inspected_quantity);
        $this->assertSame('SORT-RECOVERY-100', $released->sorting_evidence_reference);
        $this->assertSame($this->sorter->id, (int) $released->sorting_performed_by);
        $this->assertSame($this->approver->id, (int) $released->sorting_approved_by);
    }

    public function test_invalid_sorting_proof_quantities_and_approvers_do_not_mutate_the_hold(): void
    {
        $inactiveSorter = User::factory()->create(['is_active' => false]);
        $cases = [
            ['99', ['inspected_quantity' => '20'], $this->approver],
            ['99', ['evidence_reference' => ' '], $this->approver],
            ['99', ['recovery_passed' => false], $this->approver],
            ['99', ['performed_by' => $inactiveSorter->id], $this->approver],
            ['99', [], $this->sorter],
            ['99', [], $this->maker],
            ['101', [], $this->approver],
            ['99.5', [], $this->approver],
            ['100', [], $this->approver],
        ];
        foreach ($cases as [$quantity, $evidence, $approver]) {
            $beforeInspection = $this->inspection->fresh()->getRawOriginal();
            $beforeNcr = $this->ncr->fresh()->getRawOriginal();
            try {
                $this->recover($quantity, $evidence, $approver);
                $this->fail('Invalid sorting release must be refused.');
            } catch (BusinessRuleException) {
                $this->assertSame($beforeInspection, $this->inspection->fresh()->getRawOriginal());
                $this->assertSame($beforeNcr, $this->ncr->fresh()->getRawOriginal());
                $this->assertFalse($this->inspection->fresh()->canReleaseOutgoing());
            }
        }
    }

    public function test_sorting_cannot_release_pieces_already_recorded_as_defective(): void
    {
        $this->inspection->forceFill(['sample_defect_count' => 2, 'defect_count' => 2])->save();
        try {
            $this->recover('99');
            $this->fail('Two known defective pieces cannot become only one rejected piece through sorting.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('already recorded as defective', $e->getMessage());
        }
        $this->assertSame(0, $this->inspection->fresh()->accepted_quantity);
        $this->recover('98');
        $this->assertSame(98, $this->inspection->fresh()->accepted_quantity);
    }

    public function test_a_result_author_other_than_the_assigned_inspector_cannot_approve_recovery(): void
    {
        $this->inspection->resultAuthors()->create(['user_id' => $this->approver->id]);
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('original inspection makers');
        $this->recover();
    }

    public function test_full_batch_recovery_is_allowed_only_with_rework(): void
    {
        $this->recover('100', disposition: 'rework');
        $this->assertSame(100, $this->inspection->fresh()->accepted_quantity);
        $this->assertSame(InspectionStatus::Failed, $this->inspection->fresh()->status);
        $this->assertTrue($this->inspection->fresh()->canReleaseOutgoing());
    }

    public function test_a_released_material_decision_cannot_be_replaced_or_cancelled(): void
    {
        $this->recover();
        $beforeNcr = $this->ncr->fresh()->getRawOriginal();
        $beforeInspection = $this->inspection->fresh()->getRawOriginal();
        foreach (['repeat', 'change', 'cancel'] as $action) {
            try {
                if ($action === 'cancel') {
                    app(NcrService::class)->cancel($this->ncr->fresh(), 'Undo release', $this->approver);
                } else {
                    $this->recover($action === 'repeat' ? '99' : '98');
                }
                $this->fail('Settled material quantities must be immutable.');
            } catch (BusinessRuleException) {
                $this->assertSame($beforeNcr, $this->ncr->fresh()->getRawOriginal());
                $this->assertSame($beforeInspection, $this->inspection->fresh()->getRawOriginal());
            }
        }
    }

    public function test_delivery_options_expose_only_the_approved_recovered_capacity_with_proof(): void
    {
        $this->recover();
        $options = app(DeliveryService::class)->inspectionOptions($this->salesOrder->id);
        $this->assertCount(1, $options);
        $this->assertSame($this->inspection->hash_id, $options[0]['id']);
        $this->assertSame(99, $options[0]['accepted_quantity']);
        $this->assertSame('99.00', $options[0]['remaining_quantity']);

        $this->ncr->fresh()->forceFill([
            'sorting_inspected_quantity' => null, 'sorting_evidence_reference' => null,
            'sorting_performed_by' => null, 'sorting_approved_by' => null, 'sorting_approved_at' => null,
        ])->save();
        $this->assertFalse($this->inspection->fresh()->canReleaseOutgoing());
        $this->assertSame([], app(DeliveryService::class)->inspectionOptions($this->salesOrder->id));
    }

    public function test_delivery_options_handle_multiple_released_inspections_with_lazy_loading_disabled(): void
    {
        $this->recover();
        $secondOutput = WorkOrderOutput::create([
            'work_order_id' => $this->output->work_order_id,
            'recorded_by' => $this->maker->id,
            'recorded_at' => now(),
            'good_count' => 1,
            'reject_count' => 0,
            'batch_code' => 'RECOVERY-SECOND',
        ]);
        $secondInspection = Inspection::create([
            'inspection_number' => 'QC-RECOVERY-SECOND',
            'stage' => 'outgoing',
            'status' => 'passed',
            'inspection_mode' => 'lot_checklist',
            'inspector_id' => $this->maker->id,
            'reviewed_by' => $this->checker->id,
            'reviewed_at' => now(),
            'product_id' => $this->inspection->product_id,
            'entity_type' => 'work_order',
            'entity_id' => $this->inspection->entity_id,
            'work_order_output_id' => $secondOutput->id,
            'batch_quantity' => 1,
            'accepted_quantity' => 1,
            'sample_size' => 1,
            'accept_count' => 1,
            'reject_count' => 0,
            'defect_count' => 0,
            'completed_at' => now(),
        ]);
        $secondInspection->resultAuthors()->create(['user_id' => $this->maker->id]);

        $options = app(DeliveryService::class)->inspectionOptions($this->salesOrder->id);

        $this->assertCount(2, $options);
        $this->assertContains($this->inspection->hash_id, array_column($options, 'id'));
        $this->assertContains($secondInspection->hash_id, array_column($options, 'id'));
    }

    public function test_recovery_api_decodes_the_sorter_and_normalizes_confirmation(): void
    {
        $role = Role::firstOrCreate(['slug' => 'qc_recovery_test'], ['name' => 'QC Recovery Test']);
        $permission = Permission::firstOrCreate(['slug' => 'quality.ncr.manage'], ['name' => 'NCR manage', 'module' => 'quality']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $this->approver->update(['role_id' => $role->id]);
        $this->actingAs($this->approver)->patchJson('/api/v1/quality/ncrs/'.$this->ncr->hash_id.'/disposition', [
            'disposition' => 'scrap', 'mrb_accepted_quantity' => '99',
            'sorting_evidence' => ['inspected_quantity' => '100', 'evidence_reference' => 'SORT-100-GOOD-99',
                'performed_by' => $this->sorter->hash_id, 'recovery_passed' => '1'],
        ])->assertSuccessful()->assertJsonPath('data.mrb_accepted_quantity', '99.000');
        $this->assertSame(99, $this->inspection->fresh()->accepted_quantity);
    }

    public function test_mrp_replaces_only_the_quantity_not_recovered(): void
    {
        $method = new \ReflectionMethod(MrpEngineService::class, 'remainingToProduce');
        $engine = app(MrpEngineService::class);
        $this->assertSame(0.0, $method->invoke($engine, $this->salesOrderItem));
        $this->recover('99');
        $this->assertSame(1.0, $method->invoke($engine, $this->salesOrderItem));
    }

    public function test_real_delivery_rejects_one_hundred_pieces_and_reserves_ninety_nine_from_the_original_lot(): void
    {
        $this->recover();
        $location = WarehouseLocation::factory()->create([
            'zone_id' => WarehouseZone::factory()->create(['zone_type' => 'finished_goods'])->id,
        ]);
        $item = Item::factory()->create([
            'code' => $this->inspection->product->part_number, 'item_type' => 'finished_good',
        ]);
        $level = StockLevel::factory()->create([
            'item_id' => $item->id, 'location_id' => $location->id,
            'quantity' => '100.000', 'weighted_avg_cost' => '4.5000',
        ]);
        $receipt = StockMovement::create([
            'item_id' => $item->id, 'to_location_id' => $location->id,
            'movement_type' => 'production_receipt', 'quantity' => '100.000',
            'unit_cost' => '4.5000', 'total_cost' => '450.00', 'lot_number' => 'RECOVERY-100',
            'reference_type' => 'work_order_output', 'reference_id' => $this->output->id,
            'created_by' => $this->maker->id,
        ]);
        app(MovementGlPostingService::class)->postFor($receipt);
        $this->assertTrue($receipt->fresh()->gl_handoff_status?->isResolved());
        $this->output->update([
            'production_receipt_movement_id' => $receipt->id, 'production_receipt_handoff_status' => 'generated',
        ]);
        $payload = [
            'sales_order_id' => $this->salesOrder->id, 'scheduled_date' => now()->toDateString(),
            'items' => [[
                'sales_order_item_id' => $this->salesOrderItem->id,
                'inspection_id' => $this->inspection->id, 'quantity' => '100.00',
            ]],
        ];
        try {
            app(DeliveryService::class)->create($payload, $this->maker);
            $this->fail('Delivery must not reserve the rejected remainder.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('remaining accepted quantity (99.00)', $error->getMessage());
        }
        $this->assertSame(0, Delivery::count());
        $this->assertSame('0.000', (string) $level->fresh()->reserved_quantity);

        $payload['items'][0]['quantity'] = '99.00';
        $delivery = app(DeliveryService::class)->create($payload, $this->maker);
        $this->assertSame('99.000', (string) $delivery->items->first()->quantity);
        $this->assertSame('99.000', (string) $level->fresh()->reserved_quantity);
        $reservation = $delivery->stockReservationBatch->reservations->first();
        try {
            app(StockMovementService::class)->move(new StockMovementInput(
                type: StockMovementType::MaterialIssue,
                itemId: $item->id, quantity: '1', fromLocationId: $location->id, lotNumber: 'RECOVERY-100', createdBy: $this->maker->id,
            ));
            $this->fail('The one defective piece cannot consume capacity already reserved for 99 good pieces.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('QC', $error->getMessage());
        }
        $this->assertSame('100.000', (string) $level->fresh()->quantity);
        $this->assertSame('RECOVERY-100', $reservation->lot_number);
        $this->assertSame($location->id, (int) $reservation->location_id);
        $this->assertSame([], app(DeliveryService::class)->inspectionOptions($this->salesOrder->id));
        $this->assertSame(InspectionStatus::Failed, $this->inspection->fresh()->status);
        $this->assertSame(100, $this->ncr->fresh()->affected_quantity);
    }

    private function recover(string $quantity = '99', array $evidence = [], ?User $approver = null, string $disposition = 'scrap'): NonConformanceReport
    {
        return app(NcrService::class)->setDisposition(
            $this->ncr->fresh(), $disposition, 'Cracked piece segregated', 'Sort and reinspect every piece',
            $approver ?? $this->approver, $quantity,
            array_replace([
                'inspected_quantity' => '100', 'evidence_reference' => 'SORT-RECOVERY-100',
                'performed_by' => $this->sorter->id, 'recovery_passed' => true,
            ], $evidence),
        );
    }
}

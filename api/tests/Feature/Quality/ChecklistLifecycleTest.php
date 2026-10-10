<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Exceptions\ForbiddenActionException;
use App\Common\Services\Pdf\PdfRenderService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Services\CoCService;
use App\Modules\Quality\Services\InspectionService;
use App\Modules\Quality\Services\InspectionSpecService;
use App\Modules\Quality\Services\ItemQualityPlanService;
use App\Modules\Quality\Services\SpcService;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChecklistLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $maker;

    private User $checker;

    private InspectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inspection.checklist_capture_enabled' => true]);
        $role = Role::firstOrCreate(['slug' => 'qc_inspector'], ['name' => 'QC Inspector']);
        foreach (['quality.inspections.manage', 'quality.inspections.review', 'quality.inspections.view', 'quality.specs.manage'] as $slug) {
            $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'module' => 'quality']);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $this->maker = User::factory()->create(['role_id' => $role->id]);
        $this->checker = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($this->maker);
        $this->service = app(InspectionService::class);
    }

    private function checks(): array
    {
        return [
            ['parameter_name' => 'Surface', 'acceptance_criterion' => 'No cracks or contamination.'],
            ['parameter_name' => 'Fit', 'acceptance_criterion' => 'Fits approved inspection gauge.', 'is_critical' => true],
        ];
    }

    private function product(): Product
    {
        $product = Product::factory()->create(['unit_of_measure' => 'PCS']);
        app(InspectionSpecService::class)->upsertForProduct($product->id, $this->checks(), $this->maker->id, null, 'checklist');

        return $product;
    }

    private function decisions($inspection): array
    {
        return $inspection->measurements->map(fn ($row) => ['id' => $row->id, 'result' => 'passed',
            ...($row->requiresSizeReading() ? ['measured_value' => (string) $row->nominal_value] : []),
        ])->all();
    }

    public function test_real_creation_pins_one_row_per_check_for_every_stage(): void
    {
        $product = $this->product();
        $workOrder = WorkOrder::factory()->create(['product_id' => $product->id, 'status' => 'in_progress']);
        $output = WorkOrderOutput::create(['work_order_id' => $workOrder->id, 'shift' => 'day', 'good_count' => 100,
            'reject_count' => 0, 'recorded_by' => $this->maker->id, 'recorded_at' => now()]);
        foreach (InspectionStage::cases() as $stage) {
            $inspection = $this->service->create(['stage' => $stage->value, 'product_id' => $product->id,
                'batch_quantity' => 100, ...($stage === InspectionStage::Outgoing ? ['work_order_output_id' => $output->id] : [])], $this->maker);
            $this->assertTrue($inspection->usesChecklistCapture());
            $this->assertCount(2, $inspection->measurements);
            $this->assertSame('100.000', $inspection->source_quantity);
            $this->assertSame('No cracks or contamination.', $inspection->measurements->first()->acceptance_criterion);
            $this->assertSame(0, $inspection->accept_count);
            $this->assertNull($inspection->measurements->first()->nominal_value);
        }
    }

    public function test_numeric_specification_drives_one_decision_per_check_for_all_stages(): void
    {
        $product = Product::factory()->create(['unit_of_measure' => 'PCS']);
        app(InspectionSpecService::class)->upsertForProduct($product->id, [
            ['parameter_name' => 'Diameter', 'parameter_type' => 'dimensional', 'unit_of_measure' => 'mm',
                'nominal_value' => '10.0000', 'tolerance_min' => '9.9000', 'tolerance_max' => '10.1000'],
            ['parameter_name' => 'Surface', 'parameter_type' => 'visual', 'notes' => 'No cracks.'],
        ], $this->maker->id);
        $wo = WorkOrder::factory()->create(['product_id' => $product->id, 'status' => 'in_progress']);
        $output = WorkOrderOutput::create(['work_order_id' => $wo->id, 'shift' => 'day', 'good_count' => 100,
            'reject_count' => 0, 'recorded_by' => $this->maker->id, 'recorded_at' => now()]);
        foreach (InspectionStage::cases() as $stage) {
            $inspection = $this->service->create(['stage' => $stage->value, 'product_id' => $product->id,
                'batch_quantity' => 100, ...($stage === InspectionStage::Outgoing ? ['work_order_output_id' => $output->id] : [])], $this->maker);
            $this->assertTrue($inspection->usesChecklistCapture());
            $this->assertCount(2, $inspection->measurements);
            $diameter = $inspection->measurements->first();
            $this->assertSame('9.9000', $diameter->tolerance_min);
            $this->assertSame('10.1000', $diameter->tolerance_max);
            $this->assertNull($diameter->measured_value);
            $this->assertNotEmpty($diameter->acceptance_criterion);
            $inspection = $this->service->recordBatchResult($inspection, ['expected_result_version' => 0,
                'coverage_confirmed' => true, 'items' => $this->decisions($inspection)], $this->maker);
            $this->assertSame('passed', $inspection->recorded_result->value);
            $this->assertSame('10.0000', $inspection->measurements->first()->measured_value);
        }
    }

    public function test_missing_acceptance_instructions_block_creation_and_revisions_do_not_rewrite_qc(): void
    {
        $product = Product::factory()->create(['unit_of_measure' => 'PCS']);
        $specService = app(InspectionSpecService::class);
        config(['inspection.checklist_capture_enabled' => false]);
        $specService->upsertForProduct($product->id, [['parameter_name' => 'Surface', 'parameter_type' => 'visual']], $this->maker->id);
        config(['inspection.checklist_capture_enabled' => true]);
        try {
            $this->service->create(['stage' => 'in_process', 'product_id' => $product->id, 'batch_quantity' => 100], $this->maker);
            $this->fail('A name alone must not invent an acceptance standard.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('acceptance criterion', $error->getMessage());
        }
        $spec = $specService->upsertForProduct($product->id, [['parameter_name' => 'Diameter', 'parameter_type' => 'dimensional',
            'unit_of_measure' => 'mm', 'nominal_value' => 10, 'tolerance_min' => 9.9, 'tolerance_max' => 10.1]], $this->maker->id, null, 'checklist');
        $inspection = $this->service->create(['stage' => 'in_process', 'product_id' => $product->id, 'batch_quantity' => 100], $this->maker);
        $specService->upsertForProduct($product->id, [['parameter_name' => 'Diameter', 'parameter_type' => 'dimensional',
            'unit_of_measure' => 'mm', 'nominal_value' => 12, 'tolerance_min' => 11.9, 'tolerance_max' => 12.1]], $this->maker->id, null, 'checklist');
        $this->assertSame('9.9000', $inspection->fresh()->measurements->first()->tolerance_min);
        $this->assertSame([], app(SpcService::class)->computeForSpec($spec->id));
    }

    public function test_diagnostic_inspections_do_not_reuse_another_products_record(): void
    {
        $first = $this->product();
        $second = $this->product();
        $a = $this->service->create(['stage' => 'in_process', 'product_id' => $first->id, 'batch_quantity' => 100], $this->maker);
        $b = $this->service->create(['stage' => 'in_process', 'product_id' => $second->id, 'batch_quantity' => 100], $this->maker);
        $this->assertNotSame($a->id, $b->id);
        $this->assertSame($second->id, $b->product_id);
    }

    public function test_product_return_lines_have_independent_full_quantity_verdicts_and_review(): void
    {
        $product = $this->product();
        $return = ReturnRequest::create(['rma_number' => 'RMA-LINE-PROOF', 'type' => 'customer_return',
            'status' => 'received', 'received_at' => now(), 'created_by' => $this->maker->id]);
        $good = $return->items()->create(['product_id' => $product->id, 'quantity' => 99, 'returned_quantity' => 99,
            'receipt_recorded' => true, 'unit_price' => 1, 'total' => 99]);
        $bad = $return->items()->create(['product_id' => $product->id, 'quantity' => 1, 'returned_quantity' => 1,
            'receipt_recorded' => true, 'unit_price' => 1, 'total' => 1]);
        $a = $this->service->createForReturnItem($good, $return, $this->maker);
        $b = $this->service->createForReturnItem($bad, $return, $this->maker);
        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(99, $a->sample_size);
        $this->assertSame(1, $b->sample_size);
        $this->assertCount(2, $a->measurements);
        $this->assertSame($a->id, $this->service->createForReturnItem($good, $return, $this->maker)->id);
        $a = $this->service->recordBatchResult($a, ['expected_result_version' => 0, 'coverage_confirmed' => true,
            'items' => $this->decisions($a)], $this->maker);
        $this->assertSame(InspectionStatus::AwaitingReview, $a->status);
        $this->patchJson('/api/v1/quality/inspections/'.$a->hash_id.'/review', ['decision' => 'passed', 'expected_result_version' => 1])->assertForbidden();
        $a = $this->service->review($a, 'passed', null, $this->checker, 1);
        $rows = $this->decisions($b);
        $rows[0] = [...$rows[0], 'result' => 'failed', 'problem_description' => 'One cracked returned piece.'];
        $b = $this->service->recordBatchResult($b, ['expected_result_version' => 0, 'coverage_confirmed' => true,
            'physical_defects_confirmed' => true, 'sample_defect_count' => 1, 'items' => $rows], $this->maker);
        $b = $this->service->review($b, 'failed', 'Crack independently verified.', $this->checker, 1);
        $this->assertSame(InspectionStatus::Passed, $a->fresh()->status);
        $this->assertSame(InspectionStatus::Failed, $b->status);
        $this->assertSame($a->id, $good->qualityInspections()->firstOrFail()->id);
        $this->assertSame($b->id, $bad->qualityInspections()->firstOrFail()->id);
    }

    public function test_item_only_return_uses_plan_and_retains_exact_bulk_quantity(): void
    {
        $item = Item::factory()->create(['unit_of_measure' => 'KG']);
        app(ItemQualityPlanService::class)->createRevision($item, ['capture_method' => 'checklist',
            'sampling_method' => 'full', 'parameters' => $this->checks()], $this->maker);
        $return = ReturnRequest::create(['rma_number' => 'RMA-BULK-PROOF', 'type' => 'supplier_return',
            'status' => 'received', 'received_at' => now(), 'created_by' => $this->maker->id]);
        $line = $return->items()->create(['item_id' => $item->id, 'quantity' => '125.500', 'returned_quantity' => '125.500',
            'receipt_recorded' => true, 'unit_price' => 1, 'total' => '125.50']);
        $inspection = $this->service->createForReturnItem($line, $return, $this->maker);
        $this->assertSame('125.500', $inspection->source_quantity);
        $this->assertSame('KG', $inspection->source_uom_code);
        $this->assertSame(1, $inspection->sample_size);
        $this->assertTrue($inspection->requiresMakerChecker());
        $this->assertCount(2, $inspection->measurements);
    }

    public function test_zero_receipt_skips_quality_without_permitting_stock_disposition(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $product = $this->product();
        $return = ReturnRequest::create(['rma_number' => 'RMA-NO-GOODS-PROOF', 'type' => 'customer_return',
            'status' => 'received', 'received_at' => now(), 'created_by' => $this->maker->id]);
        $line = $return->items()->create(['product_id' => $product->id, 'quantity' => 100, 'returned_quantity' => 0,
            'receipt_recorded' => true, 'unit_price' => 1, 'total' => 100]);
        $service = app(ReturnRequestService::class);
        $return = $service->retryInspectionHandoff($return, $this->maker);
        $this->assertSame('inspected', $return->status->value);
        $this->assertSame('not_required', $return->inspection_handoff_status->value);
        $this->assertFalse($line->qualityInspections()->exists());
        try {
            $service->dispose($return, [['item_id' => $line->hash_id, 'disposition' => 'restock']], $this->maker);
            $this->fail('Zero receipt created a stock disposition.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('zero receipt', $error->getMessage());
        }
        $return = $service->dispose($return, [['item_id' => $line->hash_id, 'disposition' => 'no_return']], $this->maker);
        $this->assertSame('disposed', $return->disposition_status);
        $this->assertNull($return->credit_note_id);
        $this->assertNull($line->fresh()->stock_movement_id);
    }

    public function test_extra_partial_return_changed_during_review_leaves_evidence_locked(): void
    {
        $product = $this->product();
        $return = ReturnRequest::create(['rma_number' => 'RMA-PARTIAL-REVIEW-PROOF', 'type' => 'customer_return',
            'status' => 'received', 'received_at' => now(), 'created_by' => $this->maker->id]);
        $line = $return->items()->create(['product_id' => $product->id, 'quantity' => 100,
            'returned_quantity' => 40, 'receipt_recorded' => true, 'unit_price' => 1, 'total' => 100]);
        $inspection = $this->service->createForReturnItem($line, $return, $this->maker);
        $this->assertSame(40, $inspection->sample_size);
        $inspection = $this->service->recordBatchResult($inspection, ['expected_result_version' => 0,
            'coverage_confirmed' => true, 'items' => $this->decisions($inspection)], $this->maker);
        $line->update(['returned_quantity' => 50]);
        try {
            $this->service->review($inspection, 'passed', null, $this->checker, 1);
            $this->fail('Changed receipt was approved using old coverage.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('Return quantity changed', $error->getMessage());
        }
        $this->assertSame(InspectionStatus::AwaitingReview, $inspection->fresh()->status);
        $this->assertSame(1, $inspection->fresh()->result_version);
    }

    public function test_extra_second_author_cannot_approve_checklist_they_helped_record(): void
    {
        $product = $this->product();
        $return = ReturnRequest::create(['rma_number' => 'RMA-MULTI-MAKER-PROOF', 'type' => 'customer_return',
            'status' => 'received', 'received_at' => now(), 'created_by' => $this->maker->id]);
        $line = $return->items()->create(['product_id' => $product->id, 'quantity' => 100,
            'returned_quantity' => 100, 'receipt_recorded' => true, 'unit_price' => 1, 'total' => 100]);
        $inspection = $this->service->createForReturnItem($line, $return, $this->maker);
        $inspection = $this->service->recordItemResults($inspection, ['expected_result_version' => 0,
            'items' => array_slice($this->decisions($inspection), 0, 1)], $this->checker);
        $inspection = $this->service->recordBatchResult($inspection, ['expected_result_version' => 1,
            'coverage_confirmed' => true, 'items' => $this->decisions($inspection)], $this->maker);
        $this->assertTrue($inspection->hasResultAuthor($this->checker));
        $this->expectException(ForbiddenActionException::class);
        $this->service->review($inspection, 'passed', null, $this->checker, 2);
    }

    public function test_bulk_source_remains_exact_and_cannot_be_approved_by_its_maker(): void
    {
        $item = Item::factory()->create(['unit_of_measure' => 'KG']);
        $grn = GoodsReceiptNote::factory()->create();
        $poLine = PurchaseOrderItem::create(['purchase_order_id' => $grn->purchase_order_id, 'item_id' => $item->id,
            'description' => 'Resin', 'quantity' => '12500.500', 'unit' => 'KG', 'unit_price' => '1.00', 'total' => '12500.50']);
        $line = GrnItem::create(['goods_receipt_note_id' => $grn->id, 'purchase_order_item_id' => $poLine->id,
            'item_id' => $item->id, 'location_id' => WarehouseLocation::factory()->create()->id,
            'quantity_received' => '12500.500', 'unit_cost' => '1.00']);
        $plan = app(ItemQualityPlanService::class)->createRevision($item, ['capture_method' => 'checklist',
            'sampling_method' => 'full', 'parameters' => $this->checks()], $this->maker);
        $inspection = $this->service->createIncomingFromPlan($plan, $line, $grn, $this->maker);
        $this->assertSame('12500.500', $inspection->source_quantity);
        $this->assertSame('KG', $inspection->source_uom_code);
        $this->assertSame('lot', $inspection->sampling_basis);
        $this->assertSame(1, $inspection->sample_size);
        $inspection = $this->service->recordBatchResult($inspection, ['expected_result_version' => 0,
            'items' => $this->decisions($inspection), 'coverage_confirmed' => true], $this->maker);
        $this->assertSame(InspectionStatus::AwaitingReview, $inspection->status);
        $this->patchJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/review', ['decision' => 'passed',
            'expected_result_version' => 1])->assertForbidden();
        $this->actingAs($this->checker);
        $this->patchJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/review', ['decision' => 'passed',
            'expected_result_version' => 1])->assertSuccessful()->assertJsonPath('data.result_version', 2);
        $this->assertSame('12500.500', $line->fresh()->quantity_received);
    }

    public function test_numeric_supplier_plan_uses_two_decisions_for_one_hundred_received_pieces(): void
    {
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);
        $grn = GoodsReceiptNote::factory()->create();
        $poLine = PurchaseOrderItem::create(['purchase_order_id' => $grn->purchase_order_id, 'item_id' => $item->id,
            'description' => 'Components', 'quantity' => 100, 'unit' => 'PCS', 'unit_price' => 1, 'total' => 100]);
        $line = GrnItem::create(['goods_receipt_note_id' => $grn->id, 'purchase_order_item_id' => $poLine->id,
            'item_id' => $item->id, 'location_id' => WarehouseLocation::factory()->create()->id,
            'quantity_received' => 100, 'unit_cost' => 1]);
        $plan = app(ItemQualityPlanService::class)->createRevision($item, ['sampling_method' => 'full',
            'parameters' => [
                ['parameter_name' => 'Diameter', 'parameter_type' => 'dimensional', 'unit_of_measure' => 'mm',
                    'nominal_value' => 10, 'tolerance_min' => 9.9, 'tolerance_max' => 10.1],
                ['parameter_name' => 'Surface', 'parameter_type' => 'visual', 'notes' => 'No cracks.'],
            ]], $this->maker);
        $inspection = $this->service->createIncomingFromPlan($plan, $line, $grn, $this->maker);
        $this->assertTrue($inspection->usesChecklistCapture());
        $this->assertSame(100, $inspection->sample_size);
        $this->assertCount(2, $inspection->measurements);
        $this->assertSame('9.9000', $inspection->measurements->first()->tolerance_min);
        $rows = $this->decisions($inspection);
        $rows[1] = [...$rows[1], 'result' => 'failed', 'problem_description' => 'One cracked component.'];
        $inspection = $this->service->recordBatchResult($inspection, ['expected_result_version' => 0,
            'coverage_confirmed' => true, 'physical_defects_confirmed' => true, 'sample_defect_count' => 1, 'items' => $rows], $this->maker);
        $this->assertSame('failed', $inspection->recorded_result->value);
        $this->assertSame(1, $inspection->sample_defect_count);
    }

    public function test_changed_output_cannot_be_approved_after_submission(): void
    {
        $product = $this->product();
        $workOrder = WorkOrder::factory()->create(['product_id' => $product->id, 'status' => 'in_progress']);
        $output = WorkOrderOutput::create(['work_order_id' => $workOrder->id, 'shift' => 'day', 'good_count' => 100,
            'reject_count' => 0, 'recorded_by' => $this->maker->id, 'recorded_at' => now()]);
        $inspection = $this->service->create(['stage' => 'outgoing', 'product_id' => $product->id,
            'batch_quantity' => 100, 'work_order_output_id' => $output->id], $this->maker);
        $inspection = $this->service->recordBatchResult($inspection, ['expected_result_version' => 0,
            'items' => $this->decisions($inspection), 'coverage_confirmed' => true], $this->maker);
        $output->update(['good_count' => 50]);
        try {
            $this->service->review($inspection, 'passed', null, $this->checker, 1);
            $this->fail('Changed output must require reconciliation.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('source output changed', $error->getMessage());
        }
        $this->assertSame(InspectionStatus::AwaitingReview, $inspection->fresh()->status);
        $this->assertSame(1, $inspection->fresh()->result_version);
    }

    public function test_return_coverage_is_pinned_to_actual_received_quantity(): void
    {
        $product = $this->product();
        $return = ReturnRequest::create([
            'rma_number' => 'RMA-CHECKLIST-SOURCE', 'type' => 'customer_return', 'status' => 'received',
            'received_at' => now(), 'created_by' => $this->maker->id,
        ]);
        $line = $return->items()->create(['product_id' => $product->id, 'quantity' => 100,
            'returned_quantity' => 50, 'receipt_recorded' => true, 'unit_price' => 1, 'total' => 100]);
        $inspection = $this->service->create(['stage' => 'customer_return', 'product_id' => $product->id,
            'batch_quantity' => 100, 'entity_type' => 'return_request', 'entity_id' => $return->id], $this->maker);
        $this->assertSame('50.000', $inspection->source_quantity);
        $this->assertSame(50, $inspection->batch_quantity);
        $line->update(['returned_quantity' => 40]);
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Return quantity changed');
        $this->service->recordBatchResult($inspection, ['expected_result_version' => 0,
            'items' => $this->decisions($inspection), 'coverage_confirmed' => true], $this->maker);
    }

    public function test_replacement_keeps_numeric_history_and_retry_returns_same_successor(): void
    {
        config(['inspection.checklist_capture_enabled' => false]);
        $product = Product::factory()->create();
        app(InspectionSpecService::class)->upsertForProduct($product->id, [['parameter_name' => 'Diameter',
            'parameter_type' => 'dimensional', 'nominal_value' => '10.0000', 'tolerance_min' => '9.9000',
            'tolerance_max' => '10.1000', 'unit_of_measure' => 'mm', 'is_critical' => true]], $this->maker->id);
        $legacy = $this->service->create(['stage' => 'in_process', 'product_id' => $product->id, 'batch_quantity' => 100], $this->maker);
        $rowCount = $legacy->measurements->count();
        config(['inspection.checklist_capture_enabled' => true]);
        app(InspectionSpecService::class)->upsertForProduct($product->id, $this->checks(), $this->maker->id, null, 'checklist');
        $successor = $this->service->replaceWithChecklist($legacy, 'Approved checklist replaces open numeric capture.', 0, $this->maker);
        $this->assertTrue($successor->usesChecklistCapture());
        $this->assertSame($legacy->id, $successor->replaces_inspection_id);
        $this->assertSame(InspectionStatus::Cancelled, $legacy->fresh()->status);
        $this->assertSame($rowCount, $legacy->measurements()->count());
        $this->assertSame('9.9000', $legacy->measurements()->first()->tolerance_min);
        $this->assertSame($successor->id, $this->service->replaceWithChecklist($legacy, 'Retry', 0, $this->maker)->id);
    }

    public function test_return_replacement_updates_the_record_link_without_erasing_history(): void
    {
        config(['inspection.checklist_capture_enabled' => false]);
        $product = Product::factory()->create(['unit_of_measure' => 'PCS']);
        app(InspectionSpecService::class)->upsertForProduct($product->id, [
            ['parameter_name' => 'Surface', 'parameter_type' => 'visual'],
        ], $this->maker->id);
        $return = ReturnRequest::create(['rma_number' => 'RMA-CHECKLIST-REPLACE', 'type' => 'customer_return',
            'status' => 'received', 'received_at' => now(), 'created_by' => $this->maker->id]);
        $return->items()->create(['product_id' => $product->id, 'quantity' => 5, 'returned_quantity' => 5,
            'receipt_recorded' => true, 'unit_price' => 1, 'total' => 5]);
        $legacy = $this->service->create(['stage' => 'customer_return', 'product_id' => $product->id,
            'batch_quantity' => 5, 'entity_type' => 'return_request', 'entity_id' => $return->id], $this->maker);
        $return->update(['inspection_id' => $legacy->id]);
        config(['inspection.checklist_capture_enabled' => true]);
        app(InspectionSpecService::class)->upsertForProduct($product->id, $this->checks(), $this->maker->id, null, 'checklist');
        $next = $this->service->replaceWithChecklist($legacy, 'Approved checklist revision.', 0, $this->maker);
        $this->assertSame($next->id, $return->fresh()->inspection_id);
        $this->assertSame($legacy->id, $next->replaces_inspection_id);
        $this->assertSame(InspectionStatus::Cancelled, $legacy->fresh()->status);
    }

    public function test_known_numeric_failure_cannot_be_replaced(): void
    {
        config(['inspection.checklist_capture_enabled' => false]);
        $product = Product::factory()->create();
        app(InspectionSpecService::class)->upsertForProduct($product->id, [['parameter_name' => 'Surface',
            'parameter_type' => 'visual']], $this->maker->id);
        $inspection = $this->service->create(['stage' => 'in_process', 'product_id' => $product->id, 'batch_quantity' => 100], $this->maker);
        $inspection->measurements()->first()->forceFill(['is_pass' => false])->save();
        config(['inspection.checklist_capture_enabled' => true]);
        app(InspectionSpecService::class)->upsertForProduct($product->id, $this->checks(), $this->maker->id, null, 'checklist');
        $this->expectException(BusinessRuleException::class);
        $this->service->replaceWithChecklist($inspection, 'Attempted replacement', 0, $this->maker);
    }

    public function test_review_and_certificate_require_actual_passing_size_evidence(): void
    {
        $product = Product::factory()->create(['unit_of_measure' => 'PCS']);
        app(InspectionSpecService::class)->upsertForProduct($product->id, [
            ['parameter_name' => 'Diameter', 'parameter_type' => 'dimensional', 'unit_of_measure' => 'mm',
                'nominal_value' => '10.0000', 'tolerance_min' => '9.9000', 'tolerance_max' => '10.1000',
                'is_critical' => true],
        ], $this->maker->id, null, 'checklist');
        $workOrder = WorkOrder::factory()->create(['product_id' => $product->id, 'status' => 'in_progress']);
        $output = WorkOrderOutput::create(['work_order_id' => $workOrder->id, 'shift' => 'day', 'good_count' => 100,
            'reject_count' => 0, 'recorded_by' => $this->maker->id, 'recorded_at' => now()]);
        $inspection = $this->service->create(['stage' => 'outgoing', 'product_id' => $product->id,
            'batch_quantity' => 100, 'work_order_output_id' => $output->id], $this->maker);
        $inspection = $this->service->recordBatchResult($inspection, ['expected_result_version' => 0,
            'coverage_confirmed' => true, 'items' => $this->decisions($inspection)], $this->maker);
        $row = $inspection->measurements->first();
        DB::table('inspection_measurements')->where('id', $row->id)->update(['measured_value' => null]);
        try {
            $this->service->review($inspection, 'passed', null, $this->checker, 1);
            $this->fail('Missing size evidence must block review.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('complete checklist evidence', $error->getMessage());
        }
        DB::table('inspection_measurements')->where('id', $row->id)->update(['measured_value' => '10.0000']);
        $inspection = $this->service->review($inspection, 'passed', null, $this->checker, 1);
        $inspection->forceFill(['work_order_output_id' => null, 'entity_id' => null, 'entity_type' => null])->save();
        $this->mock(PdfRenderService::class, function ($mock) {
            $mock->shouldReceive('render')->once()->withArgs(function ($view, $payload, $options) {
                $this->assertSame('10.0000', $payload['checklist_results'][0]['measured']);

                return true;
            })->andReturn('%PDF-size-check');
        });
        $this->assertStringStartsWith('%PDF', app(CoCService::class)->buildBinaryForInspection($inspection->fresh())['contents']);
        DB::table('inspection_measurements')->where('id', $row->id)->update(['measured_value' => '10.2000']);
        $this->assertStringContainsString('contradicts', app(CoCService::class)->unavailableReason($inspection->fresh()));
    }

    public function test_checklist_certificate_prints_only_actual_check_evidence(): void
    {
        $product = $this->product();
        $inspection = $this->service->create(['stage' => 'customer_return', 'product_id' => $product->id, 'batch_quantity' => 100], $this->maker);
        $inspection->forceFill(['stage' => 'outgoing', 'status' => 'passed', 'reviewed_by' => $this->checker->id,
            'reviewed_at' => now(), 'inspected_quantity' => $inspection->sample_size, 'sample_defect_count' => 0])->save();
        $inspection->measurements()->update(['item_result' => 'passed']);
        $this->mock(PdfRenderService::class, function ($mock) {
            $mock->shouldReceive('render')->once()->withArgs(function ($view, $payload, $options) {
                $this->assertSame('pdf.coc', $view);
                $this->assertSame('checklist', $payload['capture_method']);
                $this->assertCount(2, $payload['checklist_results']);
                $this->assertSame([], $payload['critical_measurements']);
                $this->assertSame($this->checker->name, $payload['reviewer_name']);

                return true;
            })->andReturn('%PDF-checklist');
        });
        $this->assertStringStartsWith('%PDF', app(CoCService::class)->buildBinaryForInspection($inspection->fresh())['contents']);
    }
}

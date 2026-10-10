<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\HR\Models\Employee;
use App\Modules\Production\Models\ProductRouting;
use App\Modules\Production\Models\RoutingOperation;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Production\Services\WorkOrderOutputService;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Services\WorkOrderFinalInspectionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinishFirstQcPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_policy_keeps_new_work_orders_legacy(): void
    {
        config(['inspection.finish_first_wo_enabled' => false]);

        $workOrder = WorkOrder::factory()->create();

        $this->assertNull($workOrder->getAttribute('qc_workflow'));
        $this->assertFalse($workOrder->usesFinishFirstQc());
    }

    public function test_enabled_policy_pins_eligible_root_and_replacement_work_orders(): void
    {
        config(['inspection.finish_first_wo_enabled' => true]);

        $root = WorkOrder::factory()->create();
        $replacement = WorkOrder::factory()->create(['parent_ncr_id' => 123]);

        $this->assertSame('finish_first_v1', $root->getAttribute('qc_workflow'));
        $this->assertSame('finish_first_v1', $replacement->getAttribute('qc_workflow'));
        $root->assertProcessQualityReleased();
    }

    public function test_final_inspection_response_includes_hashed_work_order_context(): void
    {
        config(['inspection.finish_first_wo_enabled' => true]);
        $this->seed(RolePermissionSeeder::class);
        $actor = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);
        $product = Product::factory()->create(['part_number' => 'PART-CTX', 'name' => 'Context Product', 'unit_of_measure' => 'PCS']);
        $salesOrder = SalesOrder::factory()->create();
        $salesOrderItem = SalesOrderItem::factory()->create([
            'sales_order_id' => $salesOrder->id, 'product_id' => $product->id,
            'requested_delivery_date' => '2026-11-01', 'delivery_date' => '2026-11-08',
        ]);
        $workOrder = WorkOrder::factory()->create([
            'product_id' => $product->id, 'sales_order_id' => $salesOrder->id, 'sales_order_item_id' => $salesOrderItem->id,
            'batch_number' => 'LOT-CTX', 'status' => 'completed', 'actual_start' => '2026-10-01 08:00:00',
            'actual_end' => '2026-10-01 12:00:00', 'planned_end' => '2026-10-02 17:00:00',
            'quantity_target' => 1, 'quantity_produced' => 1, 'quantity_good' => 1,
        ]);
        WorkOrderOutput::create(['work_order_id' => $workOrder->id, 'recorded_by' => $actor->id,
            'recorded_at' => now(), 'good_count' => 1, 'reject_count' => 0, 'batch_code' => 'OUTPUT-CTX']);
        $spec = InspectionSpec::create(['product_id' => $product->id, 'version' => 1, 'is_active' => true, 'created_by' => $actor->id]);
        InspectionSpecItem::create(['inspection_spec_id' => $spec->id, 'parameter_name' => 'Appearance',
            'parameter_type' => 'visual', 'acceptance_criterion' => 'No damage', 'is_critical' => false]);

        $service = app(WorkOrderFinalInspectionService::class);
        $inspection = $service->ensureForCompletedWorkOrder($workOrder, $actor)->first();
        $inspection->forceFill(['inspector_id' => $actor->id])->save();
        $response = $service->show($workOrder, $actor);

        $this->assertSame([
            'part_number' => 'PART-CTX', 'name' => 'Context Product', 'unit_of_measure' => 'PCS',
        ], $response['context']['product']);
        $this->assertSame($salesOrder->hash_id, $response['context']['sales_order']['id']);
        $this->assertSame($salesOrder->so_number, $response['context']['sales_order']['so_number']);
        $this->assertSame('2026-11-01', $response['context']['sales_order']['requested_delivery_date']);
        $this->assertSame('2026-11-08', $response['context']['sales_order']['current_delivery_date']);
        $this->assertSame('LOT-CTX', $response['context']['batch_number']);
        $this->assertSame(Carbon::parse('2026-10-01 08:00:00', config('app.timezone'))->toIso8601String(), $response['context']['production_started_at']);
        $this->assertSame(Carbon::parse('2026-10-01 12:00:00', config('app.timezone'))->toIso8601String(), $response['context']['production_finished_at']);
        $this->assertSame(Carbon::parse('2026-10-02 17:00:00', config('app.timezone'))->toIso8601String(), $response['context']['production_due_at']);
        $this->assertSame(config('app.timezone'), $response['context']['timezone']);
        $this->assertSame('OUTPUT-CTX', $response['context']['output_batches'][0]['batch_code']);
        $this->assertSame($response['batches'][0]['id'], $response['context']['output_batches'][0]['inspection_id']);
        $this->assertSame('Context Product', $response['batches'][0]['product']['name']);
        $this->assertSame($actor->name, $response['batches'][0]['inspector']['name']);
        $this->assertSame(1, $response['batches'][0]['spec']['version']);
        $this->assertNull($response['batches'][0]['reviewer']);
    }

    public function test_mandatory_routing_and_repair_work_orders_remain_legacy(): void
    {
        config(['inspection.finish_first_wo_enabled' => true]);
        $product = Product::factory()->create();
        $routing = ProductRouting::create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);
        RoutingOperation::create([
            'routing_id' => $routing->id, 'sequence' => 1, 'operation_name' => 'QC gate',
            'work_center' => 'Quality', 'cycle_time_minutes' => 1, 'qc_required' => true,
        ]);
        $mandatory = WorkOrder::factory()->create(['product_id' => $product->id]);
        $author = User::factory()->create();
        $repair = WorkOrder::factory()->create([
            'work_order_class' => 'service', 'exception_reason' => 'NCR repair work order.',
            'exception_authorized_by' => $author->id, 'created_by' => $author->id,
        ]);

        $this->assertNull($mandatory->getAttribute('qc_workflow'));
        $this->assertNull($repair->getAttribute('qc_workflow'));
    }

    public function test_pinned_workflow_is_immutable_and_unknown_workflow_fails_closed(): void
    {
        config(['inspection.finish_first_wo_enabled' => true]);
        $workOrder = WorkOrder::factory()->create();

        try {
            $workOrder->forceFill(['qc_workflow' => null])->save();
            $this->fail('Expected the pinned workflow to be immutable.');
        } catch (BusinessRuleException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        DB::table('work_orders')->where('id', $workOrder->id)->update(['qc_workflow' => 'future_v2']);
        $this->expectException(BusinessRuleException::class);
        $workOrder->fresh()->usesFinishFirstQc();
    }

    public function test_quantity_only_service_rejects_invalid_or_classified_finish_first_output(): void
    {
        config(['inspection.finish_first_wo_enabled' => true]);
        $workOrder = WorkOrder::factory()->create();
        $actor = User::factory()->create(['is_active' => true]);
        $actor->forceFill(['employee_id' => Employee::factory()->create()->id])->save();
        $service = app(WorkOrderOutputService::class);

        try {
            $service->record($workOrder, ['produced_count' => '1.5'], $actor->id);
            $this->fail('Expected non-integer produced_count to be rejected.');
        } catch (BusinessRuleException $exception) {
            $this->assertStringContainsString('positive whole produced_count', $exception->getMessage());
        }

        $this->expectException(BusinessRuleException::class);
        $service->record($workOrder, ['produced_count' => 1, 'good_count' => 1], $actor->id);
    }

    public function test_finish_first_migration_refuses_rollback_after_a_work_order_is_pinned(): void
    {
        config(['inspection.finish_first_wo_enabled' => true]);
        $workOrder = WorkOrder::factory()->create();
        $migration = require database_path('migrations/2026_10_08_236000_add_finish_first_wo_qc.php');

        try {
            $migration->down();
            $this->fail('A pinned finish-first work order must preserve the additive schema.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Preserve the additive schema', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('work_orders', 'qc_workflow'));
        $this->assertSame('finish_first_v1', DB::table('work_orders')->where('id', $workOrder->id)->value('qc_workflow'));
    }

    public function test_empty_finish_first_migration_rollback_round_trip_preserves_legacy_work_order(): void
    {
        config(['inspection.finish_first_wo_enabled' => false]);
        $legacy = WorkOrder::factory()->create();
        $migration = require database_path('migrations/2026_10_08_236000_add_finish_first_wo_qc.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('work_orders', 'qc_workflow'));
        $migration->up();

        $this->assertTrue(Schema::hasColumn('work_orders', 'qc_workflow'));
        $row = DB::table('work_orders')->where('id', $legacy->id)->first();
        $this->assertNotNull($row);
        $this->assertSame($legacy->wo_number, $row->wo_number);
        $this->assertNull($row->qc_workflow);
    }
}

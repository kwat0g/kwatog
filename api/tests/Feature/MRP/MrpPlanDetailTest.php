<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\MRP\Enums\MrpPlanStatus;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestConversionStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MrpPlanDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_ppc_plan_list_serializes_real_sales_order_statuses(): void
    {
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        $viewer = User::factory()->withRole('ppc_head')->create();
        $plans = [];
        foreach ([SalesOrderStatus::Confirmed, SalesOrderStatus::Closed] as $status) {
            $order = SalesOrder::factory()->create(['status' => $status]);
            $plan = MrpPlan::create([
                'mrp_plan_no' => 'MRP-LIST-'.$status->value,
                'sales_order_id' => $order->id,
                'version' => 1,
                'status' => MrpPlanStatus::Active,
                'generated_by' => $viewer->id,
                'generated_at' => now(),
            ]);
            $plans[$plan->hash_id] = $order;
        }

        $response = $this->actingAs($viewer)->getJson('/api/v1/mrp/plans')->assertOk();
        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertCount(2, $rows);
        foreach ($plans as $id => $order) {
            $this->assertSame($order->status->value, $rows[$id]['sales_order']['status']);
            $this->assertSame($order->hash_id, $rows[$id]['sales_order']['id']);
            $this->assertSame($order->customer->name, $rows[$id]['sales_order']['customer']['name']);
        }
    }

    public function test_plan_detail_serializes_purchase_request_enums_without_a_500(): void
    {
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        $viewer = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'ppc_head')->value('id'),
        ]);
        $salesOrder = SalesOrder::factory()->create();
        $plan = MrpPlan::create([
            'mrp_plan_no' => 'MRP-'.now()->format('Ym').'-'.fake()->unique()->numerify('####'),
            'sales_order_id' => $salesOrder->id,
            'version' => 1,
            'status' => MrpPlanStatus::Active,
            'generated_by' => $viewer->id,
            'total_lines' => 1,
            'shortages_found' => 1,
            'auto_pr_count' => 1,
            'draft_wo_count' => 0,
            'diagnostics' => [['item_id' => 1, 'action' => 'pr_created']],
            'generation_context' => ['source' => 'sales_order', 'source_id' => $salesOrder->id],
            'generated_at' => now(),
        ]);
        $purchaseRequest = PurchaseRequest::factory()->create([
            'requested_by' => $viewer->id,
            'mrp_plan_id' => $plan->id,
        ]);
        $purchaseRequest->forceFill([
            'status' => PurchaseRequestStatus::Approved,
            'po_conversion_status' => PurchaseRequestConversionStatus::SourcingPending,
        ])->save();

        $this->actingAs($viewer)
            ->getJson('/api/v1/mrp/plans/'.$plan->hash_id)
            ->assertOk()
            ->assertJsonPath('data.id', $plan->hash_id)
            ->assertJsonPath('data.purchase_requests.0.priority', 'normal')
            ->assertJsonPath('data.purchase_requests.0.status', 'approved')
            ->assertJsonPath('data.purchase_requests.0.priority_label', 'Normal')
            ->assertJsonPath('data.purchase_requests.0.status_label', 'Approved');
    }

    public function test_latest_plan_detail_includes_prior_progressed_prs_and_their_pos_only_for_its_sales_order(): void
    {
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        $viewer = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'ppc_head')->value('id'),
        ]);
        $salesOrder = SalesOrder::factory()->create();
        $otherSalesOrder = SalesOrder::factory()->create();

        $oldPlan = MrpPlan::create([
            'mrp_plan_no' => 'MRP-VIS-0001',
            'sales_order_id' => $salesOrder->id,
            'version' => 1,
            'status' => MrpPlanStatus::Superseded,
            'generated_by' => $viewer->id,
            'generated_at' => now()->subDay(),
        ]);
        $latestPlan = MrpPlan::create([
            'mrp_plan_no' => 'MRP-VIS-0002',
            'sales_order_id' => $salesOrder->id,
            'version' => 2,
            'status' => MrpPlanStatus::Active,
            'generated_by' => $viewer->id,
            'diagnostics' => [['open_purchase_requests' => 10, 'action' => 'sufficient']],
            'generated_at' => now(),
        ]);
        $otherPlan = MrpPlan::create([
            'mrp_plan_no' => 'MRP-VIS-0003',
            'sales_order_id' => $otherSalesOrder->id,
            'version' => 1,
            'status' => MrpPlanStatus::Active,
            'generated_by' => $viewer->id,
            'generated_at' => now(),
        ]);

        $workOrder = WorkOrder::factory()->create([
            'mrp_plan_id' => $oldPlan->id, 'sales_order_id' => $salesOrder->id,
            'status' => 'confirmed', 'preferred_production_date' => now()->addDay()->toDateString(),
        ]);
        $mold = Mold::create([
            'mold_code' => 'MRP-VIS-MOLD', 'name' => 'Plan detail mold', 'product_id' => $workOrder->product_id,
            'cavity_count' => 1, 'cycle_time_seconds' => 30, 'output_rate_per_hour' => 100,
            'max_shots_before_maintenance' => 100000, 'lifetime_max_shots' => 1000000, 'status' => 'available',
        ]);
        $schedule = ProductionSchedule::create([
            'work_order_id' => $workOrder->id, 'machine_id' => Machine::factory()->create()->id, 'mold_id' => $mold->id,
            'scheduled_start' => now()->addDay(), 'scheduled_end' => now()->addDay()->addHours(2),
            'priority_order' => 1, 'status' => 'confirmed', 'is_confirmed' => true,
        ]);
        $otherWorkOrder = WorkOrder::factory()->create([
            'mrp_plan_id' => $otherPlan->id, 'sales_order_id' => $otherSalesOrder->id, 'status' => 'confirmed',
        ]);

        $priorPending = PurchaseRequest::factory()->create([
            'mrp_plan_id' => $oldPlan->id, 'requested_by' => $viewer->id, 'is_auto_generated' => true,
        ]);
        $priorPending->forceFill(['status' => PurchaseRequestStatus::Pending])->save();
        $priorApproved = PurchaseRequest::factory()->create([
            'mrp_plan_id' => $oldPlan->id, 'requested_by' => $viewer->id, 'is_auto_generated' => true,
        ]);
        $priorApproved->forceFill(['status' => PurchaseRequestStatus::Approved])->save();
        $priorConverted = PurchaseRequest::factory()->create([
            'mrp_plan_id' => $oldPlan->id, 'requested_by' => $viewer->id, 'is_auto_generated' => true,
        ]);
        $priorConverted->forceFill(['status' => PurchaseRequestStatus::Converted])->save();
        $priorDraft = PurchaseRequest::factory()->create([
            'mrp_plan_id' => $oldPlan->id, 'requested_by' => $viewer->id, 'is_auto_generated' => true,
        ]);
        $priorCancelled = PurchaseRequest::factory()->create([
            'mrp_plan_id' => $oldPlan->id, 'requested_by' => $viewer->id, 'is_auto_generated' => true,
        ]);
        $priorCancelled->forceFill(['status' => PurchaseRequestStatus::Cancelled])->save();
        $manualOnOldPlan = PurchaseRequest::factory()->create([
            'mrp_plan_id' => $oldPlan->id, 'requested_by' => $viewer->id, 'is_auto_generated' => false,
        ]);
        $manualOnOldPlan->forceFill(['status' => PurchaseRequestStatus::Pending])->save();
        $currentDraft = PurchaseRequest::factory()->create([
            'mrp_plan_id' => $latestPlan->id, 'requested_by' => $viewer->id, 'is_auto_generated' => true,
        ]);
        $otherPending = PurchaseRequest::factory()->create([
            'mrp_plan_id' => $otherPlan->id, 'requested_by' => $viewer->id, 'is_auto_generated' => true,
        ]);
        $otherPending->forceFill(['status' => PurchaseRequestStatus::Pending])->save();

        $po = PurchaseOrder::factory()->create([
            'purchase_request_id' => $priorApproved->id, 'created_by' => $viewer->id,
        ]);
        $po->forceFill(['status' => PurchaseOrderStatus::Sent])->save();
        $convertedPo = PurchaseOrder::factory()->create([
            'purchase_request_id' => $priorConverted->id, 'created_by' => $viewer->id,
        ]);
        $convertedPo->forceFill(['status' => PurchaseOrderStatus::PartiallyReceived])->save();

        foreach (['/api/v1/mrp/plans/'.$latestPlan->hash_id, '/api/v1/mrp/sales-orders/'.$salesOrder->hash_id.'/mrp-plan'] as $url) {
            $response = $this->actingAs($viewer)->getJson($url)->assertOk();
            $requests = collect($response->json('data.purchase_requests'))->keyBy('id');
            $workOrders = collect($response->json('data.work_orders'))->keyBy('id');
            $this->assertFalse($workOrders->has($otherWorkOrder->hash_id));
            $this->assertSame($workOrder->preferred_production_date->toDateString(), $workOrders[$workOrder->hash_id]['preferred_production_date']);
            $this->assertSame($schedule->scheduled_start->toIso8601String(), $workOrders[$workOrder->hash_id]['schedule']['scheduled_start'] ?? null);
            $this->assertSame($schedule->scheduled_end->toIso8601String(), $workOrders[$workOrder->hash_id]['schedule']['scheduled_end'] ?? null);

            $this->assertEqualsCanonicalizing(
                [$currentDraft->hash_id, $priorPending->hash_id, $priorApproved->hash_id, $priorConverted->hash_id],
                $requests->keys()->all(),
            );
            $this->assertFalse($requests->has($priorDraft->hash_id));
            $this->assertFalse($requests->has($priorCancelled->hash_id));
            $this->assertFalse($requests->has($manualOnOldPlan->hash_id));
            $this->assertFalse($requests->has($otherPending->hash_id));
            $this->assertSame('pending', $requests[$priorPending->hash_id]['status']);
            $this->assertSame($po->hash_id, $requests[$priorApproved->hash_id]['purchase_orders'][0]['id']);
            $this->assertSame($po->po_number, $requests[$priorApproved->hash_id]['purchase_orders'][0]['po_number']);
            $this->assertSame('sent', $requests[$priorApproved->hash_id]['purchase_orders'][0]['status']);
            $this->assertSame('Sent', $requests[$priorApproved->hash_id]['purchase_orders'][0]['status_label']);
            $this->assertSame($convertedPo->hash_id, $requests[$priorConverted->hash_id]['purchase_orders'][0]['id']);
            $this->assertSame('partially_received', $requests[$priorConverted->hash_id]['purchase_orders'][0]['status']);
            $this->assertSame([], $requests[$priorPending->hash_id]['purchase_orders']);
            $this->assertNotSame($po->id, $requests[$priorApproved->hash_id]['purchase_orders'][0]['id']);
        }
    }
}

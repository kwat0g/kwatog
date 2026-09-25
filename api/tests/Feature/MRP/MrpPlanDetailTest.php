<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\MRP\Enums\MrpPlanStatus;
use App\Modules\MRP\Models\MrpPlan;
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

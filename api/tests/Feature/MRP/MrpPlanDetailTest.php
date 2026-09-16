<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\MRP\Enums\MrpPlanStatus;
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\Purchasing\Enums\PurchaseRequestConversionStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
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
}

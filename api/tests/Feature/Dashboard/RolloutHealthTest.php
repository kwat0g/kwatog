<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\ActionCenterService;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseScanEvent;
use App\Modules\Quality\Models\ItemQualityPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolloutHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_health_report_surfaces_coverage_missed_qc_and_scanner_failures(): void
    {
        $role = Role::query()->create(['name' => 'Administrator', 'slug' => 'rollout-admin-test']);
        $permission = Permission::query()->create([
            'name' => 'Admin dashboard', 'slug' => 'dashboard.admin.view', 'module' => 'dashboard',
        ]);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['role_id' => $role->id]);
        $covered = Item::factory()->create(['code' => 'RM-001', 'item_type' => 'raw_material', 'is_active' => true]);
        $missing = Item::factory()->create(['code' => 'RM-002', 'item_type' => 'raw_material', 'is_active' => true]);
        Item::factory()->create(['code' => 'FG-001', 'item_type' => 'finished_good', 'is_active' => true]);
        ItemQualityPlan::query()->create([
            'item_id' => $covered->id, 'version' => 1, 'stage' => 'incoming', 'sampling_method' => 'fixed',
            'fixed_sample_size' => 3, 'parameters' => [], 'effective_from' => now()->toDateString(),
            'is_active' => true, 'created_by' => $user->id,
        ]);
        GoodsReceiptNote::factory()->create(['status' => 'pending_qc', 'created_at' => now()->subHour()]);
        WarehouseScanEvent::query()->create([
            'user_id' => $user->id, 'barcode' => 'RM-001', 'result_type' => 'item', 'is_recognized' => true,
        ]);
        WarehouseScanEvent::query()->create([
            'user_id' => $user->id, 'barcode' => 'BAD-CODE', 'result_type' => 'unknown', 'is_recognized' => false,
        ]);
        $this->mock(ActionCenterService::class)->shouldReceive('for')->once()->andReturn([
            'items' => [],
            'summary' => ['total' => 0, 'critical' => 0, 'high' => 0, 'overdue' => 0, 'owned_by_me' => 0, 'unassigned' => 0, 'by_category' => []],
            'generated_at' => now()->toIso8601String(),
        ]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboards/rollout-health')
            ->assertOk()
            ->assertJsonPath('data.status', 'attention')
            ->assertJsonPath('data.quality_plans.eligible_items', 2)
            ->assertJsonPath('data.quality_plans.covered_items', 1)
            ->assertJsonPath('data.quality_plans.coverage_percent', 50)
            ->assertJsonPath('data.quality_plans.missing.0.id', $missing->hash_id)
            ->assertJsonPath('data.qc_triggers.pending_grns_without_inspection', 1)
            ->assertJsonPath('data.scanner.scans_24h', 2)
            ->assertJsonPath('data.scanner.unrecognized_24h', 1)
            ->assertJsonPath('data.scanner.recognition_rate', 50)
            ->assertJsonPath('data.scanner.top_unrecognized.0.barcode', 'BAD-CODE');
    }

    public function test_health_report_requires_admin_dashboard_permission(): void
    {
        $role = Role::query()->create(['name' => 'Basic', 'slug' => 'rollout-basic-test']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboards/rollout-health')->assertForbidden();
    }
}

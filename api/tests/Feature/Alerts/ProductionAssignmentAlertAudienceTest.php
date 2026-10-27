<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Common\Services\AlertEngineService;
use App\Modules\Auth\Models\User;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionAssignmentAlertAudienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignment_alert_excludes_role_recipients_without_work_order_access(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $manager = User::factory()->withRole('production_manager')->create(['is_active' => true]);
        $ppc = User::factory()->withRole('ppc_head')->create(['is_active' => true]);
        $technician = User::factory()->withRole('maintenance_tech')->create(['is_active' => true]);
        $machine = Machine::factory()->create();
        $workOrder = WorkOrder::factory()->create([
            'status' => 'confirmed',
            'machine_id' => $machine->id,
            'planned_start' => now()->subHours(2),
            'planned_end' => now()->subHour(),
        ]);
        $mold = Mold::create([
            'product_id' => $workOrder->product_id,
            'mold_code' => 'ALERT-AUDIENCE-MOLD',
            'name' => 'Alert audience mold',
            'cavity_count' => 1,
            'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 100,
            'setup_time_minutes' => 10,
            'current_shot_count' => 0,
            'max_shots_before_maintenance' => 10000,
            'lifetime_max_shots' => 100000,
            'status' => 'available',
        ]);
        $workOrder->update(['mold_id' => $mold->id]);
        ProductionSchedule::create([
            'work_order_id' => $workOrder->id,
            'machine_id' => $machine->id,
            'mold_id' => $mold->id,
            'priority_order' => 0,
            'scheduled_start' => now()->subHours(2),
            'scheduled_end' => now()->subHour(),
            'status' => 'confirmed',
            'is_confirmed' => true,
            'reserves_capacity' => true,
        ]);

        $this->assertTrue($manager->hasPermission('production.view'));
        $this->assertTrue($manager->hasPermission('production.work_orders.view'));
        $this->assertTrue($ppc->hasPermission('production.view'));
        $this->assertTrue($ppc->hasPermission('production.work_orders.view'));
        $this->assertFalse($technician->hasPermission('production.view'));
        $this->assertFalse($technician->hasPermission('production.work_orders.view'));

        app(AlertEngineService::class)->runAllChecks();

        $this->assertDatabaseHas('notifications', [
            'type' => 'production.assignment.review',
            'notifiable_id' => $manager->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'type' => 'production.assignment.review',
            'notifiable_id' => $ppc->id,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'type' => 'production.assignment.review',
            'notifiable_id' => $technician->id,
        ]);

        $notice = DB::table('notifications')
            ->where('type', 'production.assignment.review')
            ->where('notifiable_id', $manager->id)
            ->first();
        $this->assertSame('/production/work-orders/'.$workOrder->hash_id, json_decode($notice->data, true)['link_to']);
    }
}

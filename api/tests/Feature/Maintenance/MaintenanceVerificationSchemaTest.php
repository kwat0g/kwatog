<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\MRP\Models\Machine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MaintenanceVerificationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_deployments_receive_non_admin_supervisor_role(): void
    {
        $supervisor = Role::where('slug', 'maintenance_supervisor')->first();
        $this->assertNotNull($supervisor);
        $this->assertTrue($supervisor->permissions()->where('slug', 'maintenance.wo.assign')->exists());
        $this->assertTrue($supervisor->permissions()->where('slug', 'maintenance.schedules.manage')->exists());
        $this->assertFalse($supervisor->permissions()->where('slug', 'admin.users.manage')->exists());
    }

    public function test_existing_order_shape_defaults_to_legacy_completion(): void
    {
        $this->assertTrue(Schema::hasColumn('maintenance_work_orders', 'verification_required'));

        $order = MaintenanceWorkOrder::create([
            'mwo_number' => 'SCHEMA-LEGACY-1',
            'maintainable_type' => 'machine',
            'maintainable_id' => Machine::factory()->create()->id,
            'type' => 'corrective',
            'priority' => 'medium',
            'description' => 'Open work predating verification rollout',
            'status' => 'open',
            'created_by' => User::factory()->create()->id,
        ]);

        $this->assertFalse((bool) $order->fresh()->verification_required);
        $this->assertNull($order->fresh()->repair_submitted_at);
        $this->assertNull($order->fresh()->verified_at);
        $this->assertFalse(json_decode((string) DB::table('settings')
            ->where('key', 'maintenance.closed_loop.enabled')->value('value'), true));
    }
}

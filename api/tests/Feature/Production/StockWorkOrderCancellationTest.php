<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\MaterialReservation;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockWorkOrderCancellationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->actingAs(User::factory()->create(['role_id' => Role::where('slug', 'ppc_head')->value('id')]));
    }

    private function stock(array $attributes = []): WorkOrder
    {
        return WorkOrder::factory()->create(['production_purpose' => 'stock', ...$attributes]);
    }

    private function cancel(WorkOrder $wo, string $reason = 'Replenishment no longer needed.')
    {
        return $this->postJson('/api/v1/production/work-orders/'.$wo->hash_id.'/cancel', ['reason' => $reason]);
    }

    public function test_ppc_can_cancel_stock_without_permission_to_start_production(): void
    {
        $wo = $this->stock();
        $this->assertFalse(auth()->user()->hasPermission('production.work_orders.lifecycle'));
        $this->cancel($wo)->assertOk();
        $this->assertSame('cancelled', $wo->fresh()->status->value);
        $this->assertSame('Replenishment no longer needed.', $wo->fresh()->pause_reason);
        $this->cancel($wo)->assertStatus(409);
        $this->cancel(WorkOrder::factory()->create(['production_purpose' => 'order']))->assertForbidden();
        $this->cancel($this->stock(['parent_wo_id' => $this->stock()->id]))->assertForbidden();
    }

    public function test_reason_is_required_and_stock_cannot_be_deleted_to_bypass_cleanup(): void
    {
        $wo = $this->stock();
        $this->cancel($wo, '   ')->assertStatus(422);
        $this->cancel($wo, str_repeat('x', 501))->assertStatus(422);
        $this->deleteJson('/api/v1/production/work-orders/'.$wo->hash_id)->assertStatus(422);
        $this->assertSame('planned', $wo->fresh()->status->value);
    }

    public function test_confirmed_stock_releases_bookings_and_holds_preserving_history(): void
    {
        $machine = Machine::factory()->create();
        $wo = $this->stock(['status' => 'confirmed', 'machine_id' => $machine->id]);
        $mold = Mold::create(['product_id' => $wo->product_id, 'mold_code' => 'CANCEL-MOLD', 'name' => 'Cancellation mold',
            'cavity_count' => 1, 'cycle_time_seconds' => 30, 'output_rate_per_hour' => 100, 'setup_time_minutes' => 10,
            'current_shot_count' => 0, 'max_shots_before_maintenance' => 1000, 'lifetime_max_shots' => 10000, 'status' => 'available']);
        $wo->update(['mold_id' => $mold->id]);
        $schedule = ProductionSchedule::create(['work_order_id' => $wo->id, 'machine_id' => $machine->id, 'mold_id' => $mold->id,
            'scheduled_start' => now()->addDay(), 'scheduled_end' => now()->addDay()->addHour(),
            'priority_order' => 0, 'status' => 'confirmed', 'is_confirmed' => true, 'reserves_capacity' => true]);
        $worker = Employee::factory()->create();
        $crew = $wo->crews()->create(['recorder_employee_id' => $worker->id, 'assigned_by' => auth()->id(),
            'reason' => 'Initial assignment.', 'assigned_at' => now()]);
        $member = $crew->members()->create(['employee_id' => $worker->id, 'employee_snapshot' => ['name' => 'Assigned worker']]);
        $level = StockLevel::factory()->create(['quantity' => 10, 'reserved_quantity' => 3]);
        $reservation = MaterialReservation::create(['work_order_id' => $wo->id, 'item_id' => $level->item_id,
            'location_id' => $level->location_id, 'quantity' => 3, 'status' => 'reserved', 'reserved_at' => now()]);
        $child = $this->stock(['parent_wo_id' => $wo->id]);
        $request = PurchaseRequest::factory()->create(['stock_production_work_order_id' => $wo->id, 'is_auto_generated' => true, 'status' => 'draft']);
        $this->cancel($wo)->assertOk();
        $this->assertNotNull($crew->fresh()->ended_at);
        $this->assertNotNull($member->fresh());
        $this->assertSame('superseded', $schedule->fresh()->status->value);
        $this->assertFalse($schedule->fresh()->reserves_capacity);
        $this->assertSame('released', $reservation->fresh()->status->value);
        $this->assertSame('0.000', $level->fresh()->reserved_quantity);
        $this->assertSame('10.000', $level->fresh()->quantity);
        $this->assertSame('cancelled', $child->fresh()->status->value);
        $this->assertSame('cancelled', $request->fresh()->status->value);
        $this->assertNotNull($wo->fresh()->planned_start);
        $this->assertSame($machine->id, $wo->fresh()->machine_id);
    }

    public function test_issued_parent_materials_block_entire_tree_without_cancelling_draft_purchasing(): void
    {
        $wo = $this->stock(['status' => 'confirmed']);
        $child = $this->stock(['parent_wo_id' => $wo->id]);
        $request = PurchaseRequest::factory()->create(['stock_production_work_order_id' => $wo->id, 'is_auto_generated' => true, 'status' => 'draft']);
        MaterialIssueSlip::create(['work_order_id' => $wo->id, 'slip_number' => 'MIS-CANCEL-001', 'status' => 'issued',
            'issued_date' => now(), 'issued_by' => auth()->id(), 'created_by' => auth()->id(), 'total_value' => 0]);
        $this->cancel($wo)->assertStatus(422)->assertJsonPath('message', 'Issued materials must be reconciled before cancelling stock production.');
        $this->assertSame('confirmed', $wo->fresh()->status->value);
        $this->assertSame('planned', $child->fresh()->status->value);
        $this->assertSame('draft', $request->fresh()->status->value);
    }

    public function test_started_and_paused_stock_cannot_be_cancelled_even_without_output(): void
    {
        $this->cancel($this->stock(['status' => 'confirmed', 'actual_start' => now()]))->assertStatus(422);
        $this->cancel($this->stock(['status' => 'paused']))->assertStatus(422);
        $wo = $this->stock(['status' => 'confirmed']);
        $wo->operations()->create(['sequence' => 1, 'operation_name' => 'Molding', 'status' => 'setup', 'setup_start' => now(), 'qty_planned' => 10]);
        $this->cancel($wo)->assertStatus(422);
        $this->assertSame('confirmed', $wo->fresh()->status->value);
    }

    public function test_committed_child_or_purchasing_preserves_all_parent_holds(): void
    {
        $wo = $this->stock(['status' => 'confirmed']);
        $level = StockLevel::factory()->create(['quantity' => 10, 'reserved_quantity' => 3]);
        $hold = MaterialReservation::create(['work_order_id' => $wo->id, 'item_id' => $level->item_id,
            'location_id' => $level->location_id, 'quantity' => 3, 'status' => 'reserved', 'reserved_at' => now()]);
        $child = $this->stock(['parent_wo_id' => $wo->id, 'status' => 'confirmed']);
        $this->cancel($wo)->assertStatus(422);
        $child->update(['status' => 'planned']);
        $request = PurchaseRequest::factory()->create(['stock_production_work_order_id' => $wo->id, 'is_auto_generated' => true]);
        $request->forceFill(['status' => 'approved'])->save();
        $this->cancel($wo)->assertStatus(422);
        $this->assertSame('confirmed', $wo->fresh()->status->value);
        $this->assertSame('planned', $child->fresh()->status->value);
        $this->assertSame('approved', $request->fresh()->status->value);
        $this->assertSame('reserved', $hold->fresh()->status->value);
        $this->assertSame('3.000', $level->fresh()->reserved_quantity);
    }

    public function test_permission_migration_is_additive_idempotent_and_preserves_custom_grants(): void
    {
        $roleId = auth()->user()->role_id;
        $permissionId = DB::table('permissions')->where('slug', 'production.wo.cancel_stock')->value('id');
        DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();
        auth()->user()->flushPermissionsCache();
        $this->assertFalse(auth()->user()->hasPermission('production.wo.cancel_stock'));
        $before = DB::table('role_permissions')->where('role_id', $roleId)->count();
        $migration = require database_path('migrations/2026_10_09_230000_add_stock_work_order_cancellation_permission.php');
        $migration->up();
        $migration->up();
        $this->assertFalse(Cache::has("auth:role_perms:{$roleId}"));
        auth()->user()->flushPermissionsCache();
        $this->assertSame($before + 1, DB::table('role_permissions')->where('role_id', $roleId)->count());
        $this->assertTrue(auth()->user()->hasPermission('production.wo.cancel_stock'));
        $this->assertFalse(auth()->user()->hasPermission('production.work_orders.lifecycle'));
        $migration->down();
        $this->assertSame($before + 1, DB::table('role_permissions')->where('role_id', $roleId)->count());
    }

    public function test_late_ledger_failure_rolls_back_child_and_purchase_request_cancellation(): void
    {
        $wo = $this->stock(['status' => 'confirmed']);
        $child = $this->stock(['parent_wo_id' => $wo->id]);
        $request = PurchaseRequest::factory()->create(['stock_production_work_order_id' => $wo->id, 'is_auto_generated' => true]);
        $level = StockLevel::factory()->create(['quantity' => 10, 'reserved_quantity' => 0]);
        $hold = MaterialReservation::create(['work_order_id' => $wo->id, 'item_id' => $level->item_id,
            'location_id' => $level->location_id, 'quantity' => 3, 'status' => 'reserved', 'reserved_at' => now()]);
        $this->cancel($wo)->assertStatus(422);
        $this->assertSame('confirmed', $wo->fresh()->status->value);
        $this->assertSame('planned', $child->fresh()->status->value);
        $this->assertSame('draft', $request->fresh()->status->value);
        $this->assertSame('reserved', $hold->fresh()->status->value);
        $this->assertSame('0.000', $level->fresh()->reserved_quantity);
    }

    public function test_existing_order_cancellation_keeps_optional_reason_and_lifecycle_permission(): void
    {
        $this->actingAs(User::factory()->create(['role_id' => Role::where('slug', 'production_manager')->value('id')]));
        $wo = WorkOrder::factory()->create(['production_purpose' => 'order']);
        $cancelled = app(WorkOrderService::class)->cancel($wo);
        $this->assertSame('cancelled', $cancelled->status->value);
        $this->assertNull($cancelled->pause_reason);
    }
}

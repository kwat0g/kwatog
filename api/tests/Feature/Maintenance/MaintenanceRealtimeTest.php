<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Common\Models\OutboxMessage;
use App\Modules\Auth\Models\User;
use App\Modules\Maintenance\Models\MaintenanceLog;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\MRP\Models\Machine;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MaintenanceRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_work_order_and_detail_children_coalesce_to_one_post_commit_refresh(): void
    {
        $actor = User::factory()->create();
        $machine = Machine::factory()->create(['status' => 'idle']);

        DB::transaction(function () use ($actor, $machine): void {
            $order = MaintenanceWorkOrder::query()->create([
                'mwo_number' => 'MWO-RT-'.uniqid(),
                'maintainable_type' => 'machine',
                'maintainable_id' => $machine->id,
                'type' => 'corrective',
                'priority' => 'high',
                'description' => 'Realtime regression fixture',
                'status' => 'open',
                'created_by' => $actor->id,
            ]);
            $order->update(['status' => 'assigned']);

            MaintenanceLog::query()->create([
                'work_order_id' => $order->id,
                'description' => 'Check guarded refresh',
                'logged_by' => $actor->id,
                'created_at' => now(),
            ]);
        });

        $messages = OutboxMessage::query()
            ->where('event_type', 'App\\Modules\\Maintenance\\Events\\MaintenanceWorkOrderChanged')
            ->get();

        $this->assertCount(1, $messages);
        $this->assertSame('pending', $messages->first()->status);
        $this->assertSame('updated', $messages->first()->payload['action']);
        $this->assertArrayHasKey('hashId', $messages->first()->payload);
    }
}

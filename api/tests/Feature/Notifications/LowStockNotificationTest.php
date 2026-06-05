<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Events\LowStockPrCreated;
use App\Modules\Inventory\Listeners\NotifyOnLowStockPrCreated;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LowStockNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_listener_sends_to_purchasing_officer_and_warehouse(): void
    {
        $po        = $this->userWithRole('purchasing_officer');
        $warehouse = $this->userWithRole('warehouse_staff');

        $item     = Item::factory()->create(['reorder_point' => 50]);
        $pr       = PurchaseRequest::factory()->create();
        $listener = app(NotifyOnLowStockPrCreated::class);
        $listener->handle(new LowStockPrCreated($item, $pr));

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $po->id,
            'type'          => 'inventory.low_stock',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $warehouse->id,
            'type'          => 'inventory.low_stock',
        ]);
    }

    public function test_notification_contains_item_and_pr_details(): void
    {
        $this->userWithRole('purchasing_officer');

        $item = Item::factory()->create(['code' => 'ITM-TEST01', 'name' => 'Test Resin Pellets']);
        $pr   = PurchaseRequest::factory()->create(['pr_number' => 'PR-202606-0001']);

        $listener = app(NotifyOnLowStockPrCreated::class);
        $listener->handle(new LowStockPrCreated($item, $pr));

        $row = \DB::table('notifications')
            ->where('type', 'inventory.low_stock')
            ->first();

        $this->assertNotNull($row);
        $data = json_decode($row->data, true);
        $this->assertStringContainsString('ITM-TEST01', $data['title']);
        $this->assertStringContainsString('Test Resin Pellets', $data['message']);
        $this->assertStringContainsString('PR-202606-0001', $data['message']);
        $this->assertStringContainsString('/purchasing/purchase-requests/', $data['link_to']);
    }

    public function test_listener_class_exists(): void
    {
        $this->assertTrue(
            class_exists(NotifyOnLowStockPrCreated::class),
            'Listener class NotifyOnLowStockPrCreated should exist',
        );
    }

    public function test_listener_does_not_notify_inactive_purchasing_officer(): void
    {
        $role = \App\Modules\Auth\Models\Role::where('slug', 'purchasing_officer')->firstOrFail();
        $inactive = \App\Modules\Auth\Models\User::factory()->create([
            'role_id'   => $role->id,
            'is_active' => false,
        ]);
        $item = \App\Modules\Inventory\Models\Item::factory()->create(['reorder_point' => 50]);
        $pr   = \App\Modules\Purchasing\Models\PurchaseRequest::factory()->create();
        $listener = app(NotifyOnLowStockPrCreated::class);
        $listener->handle(new LowStockPrCreated($item, $pr));
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $inactive->id,
            'type'          => 'inventory.low_stock',
        ]);
    }

    private function userWithRole(string $slug): User
    {
        $role = Role::where('slug', $slug)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }
}

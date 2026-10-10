<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Enums\AlertType;
use App\Common\Models\Alert;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\AutoReplenishmentService;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tier-1 fix: auto-generated PRs must attribute requested_by to a real
 * system_admin user, not a hardcoded id 1.
 */
class AutoReplenishmentAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocked_setup_notifies_master_data_owner_once_and_not_bare_employee(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->withRole('system_admin')->create();
        $employee = User::factory()->withRole('employee')->create();
        $item = Item::factory()->create(['is_active' => true, 'reorder_point' => 10, 'standard_cost' => '0.00']);
        $service = app(AutoReplenishmentService::class);
        $service->checkAndReplenish($item->id);
        $service->checkAndReplenish($item->id);
        $notifications = DB::table('notifications')->where('type', 'inventory.replenishment_blocked')->get();
        $this->assertCount(1, $notifications);
        $this->assertSame($admin->id, (int) $notifications->first()->notifiable_id);
        $this->assertNotSame($employee->id, (int) $notifications->first()->notifiable_id);
        $this->assertSame('/inventory/items/'.$item->hash_id, json_decode($notifications->first()->data, true)['link_to']);
    }

    public function test_auto_pr_is_attributed_to_a_system_admin_user(): void
    {
        $role = Role::query()->create(['name' => 'System Admin', 'slug' => 'system_admin']);
        // A noise user with a LOWER id ensures we don't accidentally pass by
        // grabbing "the first user" — the admin is created second.
        User::factory()->create();
        $admin = User::factory()->create(['role_id' => $role->id]);

        // Item below reorder point with no stock → available (0) <= reorder.
        // standard_cost must be > 0: AutoReplenishmentService refuses to raise a
        // replenishment request with a zero-valued estimate ("master data must be
        // completed first"), and ItemFactory defaults the column to 0.
        $item = Item::factory()->create([
            'is_active' => true,
            'reorder_point' => 100,
            'safety_stock' => 10,
            'standard_cost' => '25.00',
        ]);

        $pr = app(AutoReplenishmentService::class)->checkAndReplenish($item->id);

        $this->assertNotNull($pr, 'A purchase request should be auto-created.');
        $this->assertTrue((bool) $pr->is_auto_generated);
        $this->assertSame($admin->id, $pr->requested_by, 'PR must be attributed to the system_admin, not id 1.');
    }

    public function test_no_pr_created_when_no_users_exist(): void
    {
        $item = Item::factory()->create(['is_active' => true, 'reorder_point' => 100, 'safety_stock' => 10]);

        // No users in the DB → service should skip gracefully (no FK crash).
        $this->assertNull(app(AutoReplenishmentService::class)->checkAndReplenish($item->id));
        $this->assertSame(0, PurchaseRequest::query()->count());
        $this->assertSame(1, Alert::query()->where('type', AlertType::ReplenishmentBlocked->value)->count());
    }

    public function test_blocked_replenishment_alert_is_deduplicated_and_resolved_after_repair(): void
    {
        $role = Role::query()->create(['name' => 'System Admin', 'slug' => 'system_admin']);
        $admin = User::factory()->create(['role_id' => $role->id]);
        $item = Item::factory()->create([
            'is_active' => true,
            'reorder_point' => 100,
            'safety_stock' => 10,
            'standard_cost' => '0.00',
        ]);

        $service = app(AutoReplenishmentService::class);
        $service->checkAndReplenish($item->id);
        $service->checkAndReplenish($item->id);

        $this->assertSame(1, Alert::query()->where('type', AlertType::ReplenishmentBlocked->value)->count());
        $item->update(['standard_cost' => '25.00']);
        $service->checkAndReplenish($item->id);
        $this->assertNotNull(Alert::query()->where('type', AlertType::ReplenishmentBlocked->value)->first()->resolved_at);
        $this->assertSame(1, PurchaseRequest::query()->where('requested_by', $admin->id)->count());
    }

    public function test_replayed_low_stock_event_does_not_create_a_second_pr(): void
    {
        $role = Role::query()->create(['name' => 'System Admin', 'slug' => 'system_admin']);
        $admin = User::factory()->create(['role_id' => $role->id]);
        $item = Item::factory()->create([
            'is_active' => true,
            'reorder_point' => 100,
            'safety_stock' => 10,
            'standard_cost' => '25.00',
        ]);

        $service = app(AutoReplenishmentService::class);
        $first = $service->checkAndReplenish($item->id);
        $second = $service->checkAndReplenish($item->id);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame($admin->id, $first->requested_by);
        $this->assertSame(1, PurchaseRequest::query()->count());
    }
}

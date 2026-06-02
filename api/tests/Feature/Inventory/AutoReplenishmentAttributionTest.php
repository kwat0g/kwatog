<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\AutoReplenishmentService;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tier-1 fix: auto-generated PRs must attribute requested_by to a real
 * system_admin user, not a hardcoded id 1.
 */
class AutoReplenishmentAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_pr_is_attributed_to_a_system_admin_user(): void
    {
        $role = Role::query()->create(['name' => 'System Admin', 'slug' => 'system_admin']);
        // A noise user with a LOWER id ensures we don't accidentally pass by
        // grabbing "the first user" — the admin is created second.
        User::factory()->create();
        $admin = User::factory()->create(['role_id' => $role->id]);

        // Item below reorder point with no stock → available (0) <= reorder.
        $item = Item::factory()->create(['is_active' => true, 'reorder_point' => 100, 'safety_stock' => 10]);

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
    }
}

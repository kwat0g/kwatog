<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockAdjustmentReason;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockAdjustmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P37 — concurrent `StockAdjustmentService::approve()` of the SAME pending
 * adjustment must post the stock movement exactly once.
 *
 * Two approvers both read the row while it is still `pending`; each holds its
 * own model instance. The first approval commits (movement posted, row →
 * approved). The second request then acts on its STALE instance: the old
 * check-then-act guard (status read on the passed model, outside the
 * transaction) does not observe the committed approval, so it posts a SECOND
 * movement. The ledger must be locked and re-read inside the transaction.
 */
class StockAdjustmentDoubleApproveRaceTest extends TestCase
{
    use RefreshDatabase;

    private Item $item;
    private WarehouseLocation $location;
    private StockAdjustmentService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->svc = app(StockAdjustmentService::class);

        $this->item     = Item::factory()->create(['is_active' => true]);
        $this->location = WarehouseLocation::factory()->create();
        StockLevel::query()->create([
            'item_id'           => $this->item->id,
            'location_id'       => $this->location->id,
            'quantity'          => '1000',
            'reserved_quantity' => '0',
            'weighted_avg_cost' => '10.0000',
            'lock_version'      => 0,
        ]);

        app(SettingsService::class)->set('inventory.adjustment_approval_threshold', 1);
    }

    private function userWith(string $roleSlug, array $permissions): User
    {
        $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => $roleSlug]);
        $role->permissions()->syncWithoutDetaching(
            Permission::query()->whereIn('slug', $permissions)->pluck('id')->all(),
        );
        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    public function test_second_concurrent_approval_is_blocked_and_posts_no_second_movement(): void
    {
        $maker   = $this->userWith('warehouse_staff', ['inventory.adjust']);
        $checker = $this->userWith('finance_officer', ['inventory.adjust.approve']);

        $pending = $this->svc->create(
            itemId: $this->item->id,
            locationId: $this->location->id,
            direction: 'out',
            qty: '5',
            unitCost: '10.0000',
            reason: 'Double-approve race ' . uniqid(),
            by: $maker,
            reasonCode: StockAdjustmentReason::Damage,
        );
        $this->assertSame('pending', $pending->status->value);

        // Two concurrent approvers each fetched the row while it was pending.
        $approverA = StockAdjustment::query()->findOrFail($pending->id);
        $approverB = StockAdjustment::query()->findOrFail($pending->id);

        // Approver A commits: movement posted, row → approved.
        $this->svc->approve($approverA, $checker);
        $this->assertSame('995.000', (string) StockLevel::query()
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->value('quantity'));

        // Approver B acts on its stale instance — must be blocked, not re-posted.
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('already approved');

        $this->svc->approve($approverB, $checker);
    }

    public function test_first_approval_posts_exactly_one_movement(): void
    {
        $maker   = $this->userWith('warehouse_staff', ['inventory.adjust']);
        $checker = $this->userWith('finance_officer', ['inventory.adjust.approve']);

        $pending = $this->svc->create(
            itemId: $this->item->id,
            locationId: $this->location->id,
            direction: 'out',
            qty: '5',
            unitCost: '10.0000',
            reason: 'Single-movement pin ' . uniqid(),
            by: $maker,
            reasonCode: StockAdjustmentReason::Damage,
        );

        $this->svc->approve(StockAdjustment::query()->findOrFail($pending->id), $checker);

        $this->assertSame(1, StockAdjustment::query()
            ->where('id', $pending->id)
            ->whereNotNull('stock_movement_id')
            ->count());
        $this->assertSame('995.000', (string) StockLevel::query()
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->value('quantity'));
    }
}

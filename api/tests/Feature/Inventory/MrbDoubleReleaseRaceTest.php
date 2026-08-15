<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\MrbStatus;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialReviewRecord;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\QuarantineService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * P40 — concurrent `QuarantineService::release()` of the SAME Held MRB must
 * release the quarantine stock exactly once.
 *
 * Two release attempts both read the MRB while it is `held`; each holds its
 * own model instance. The first release commits (stock moved out, row →
 * released/scrapped). The second attempt then acts on its STALE instance: the
 * old status guard reads the passed model outside the transaction, does not
 * observe the committed release, and re-attempts the quarantine movement. The
 * MRB row must be locked and re-read inside the transaction so the second
 * attempt fails with a clean business-rule rejection.
 */
class MrbDoubleReleaseRaceTest extends TestCase
{
    use RefreshDatabase;

    private QuarantineService $svc;
    private Warehouse $warehouse;
    private WarehouseLocation $sourceLoc;
    private WarehouseLocation $quarantineLoc;
    private WarehouseLocation $scrapLoc;
    private WarehouseLocation $goodTargetLoc;
    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([StockMovementCompleted::class]);

        $this->seed(RolePermissionSeeder::class);
        $this->svc = app(QuarantineService::class);

        $this->warehouse = Warehouse::factory()->create();
        $this->sourceLoc     = WarehouseLocation::factory()->create(['zone_id' => $this->makeZone('raw_materials')->id, 'is_active' => true]);
        $this->quarantineLoc = WarehouseLocation::factory()->create(['zone_id' => $this->makeZone('quarantine')->id, 'is_active' => true]);
        $this->scrapLoc      = WarehouseLocation::factory()->create(['zone_id' => $this->makeZone('scrap')->id, 'is_active' => true]);
        $this->goodTargetLoc = WarehouseLocation::factory()->create(['zone_id' => $this->makeZone('finished_goods')->id, 'is_active' => true]);

        $this->item = Item::factory()->create(['is_active' => true]);
    }

    private function makeZone(string $type): WarehouseZone
    {
        return WarehouseZone::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'zone_type'    => $type,
        ]);
    }

    private function userWith(string $roleSlug): User
    {
        return User::factory()->create([
            'role_id'   => Role::query()->where('slug', $roleSlug)->value('id'),
            'email'     => $roleSlug.'+'.substr(uniqid(), -8).'@t.local',
            'is_active' => true,
        ]);
    }

    private function heldMrb(User $by): MaterialReviewRecord
    {
        StockLevel::create([
            'item_id'           => $this->item->id,
            'location_id'       => $this->sourceLoc->id,
            'quantity'          => '100',
            'reserved_quantity' => '0',
            'weighted_avg_cost' => '25.0000',
            'lock_version'      => 0,
        ]);

        return $this->svc->hold([
            'item_id'                => $this->item->id,
            'quantity'               => '30',
            'source_location_id'     => $this->sourceLoc->id,
            'quarantine_location_id' => $this->quarantineLoc->id,
        ], $by);
    }

    public function test_second_concurrent_release_is_blocked_with_business_rule(): void
    {
        $warehouse = $this->userWith('warehouse_staff');
        $mrb = $this->heldMrb($warehouse);
        $this->assertSame(MrbStatus::Held, $mrb->status);

        // Two concurrent releasers each fetched the row while it was held.
        $releaserA = MaterialReviewRecord::query()->findOrFail($mrb->id);
        $releaserB = MaterialReviewRecord::query()->findOrFail($mrb->id);

        // Releaser A commits: scrap movement posted, row → scrapped.
        $this->svc->release($releaserA, 'scrap', $warehouse);
        $this->assertSame(MrbStatus::Scrapped, MaterialReviewRecord::query()->findOrFail($mrb->id)->status);

        // Releaser B acts on its stale instance — must be a business-rule
        // rejection, not a second movement attempt.
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('not held');

        $this->svc->release($releaserB, 'scrap', $warehouse);
    }

    public function test_release_records_exactly_one_release_movement(): void
    {
        $warehouse = $this->userWith('warehouse_staff');
        $mrb = $this->heldMrb($warehouse);

        $this->svc->release(MaterialReviewRecord::query()->findOrFail($mrb->id), 'scrap', $warehouse);

        $movements = $mrb->fresh()->releaseMovement;
        $this->assertNotNull($movements);
        $this->assertSame('0.000', (string) StockLevel::query()
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->quarantineLoc->id)
            ->value('quantity'));
    }
}

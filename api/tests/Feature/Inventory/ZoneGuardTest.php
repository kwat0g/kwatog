<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F-02 — the movement/reservation choke point must refuse quarantine and
 * scrap-zone stock for consumption, regardless of which caller reaches it.
 *
 * MaterialIssueService and PickingListService guard their own lookups, but any
 * other path (Delivery, future services) could still move held stock. The
 * guard belongs in StockMovementService itself so no caller can bypass it.
 */
class ZoneGuardTest extends TestCase
{
    use RefreshDatabase;

    private StockMovementService $movements;
    private Item $item;
    private WarehouseLocation $goodLocation;
    private WarehouseLocation $quarantineLocation;
    private WarehouseLocation $scrapLocation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->movements = app(StockMovementService::class);
        $this->item = Item::factory()->create();

        $this->goodLocation = WarehouseLocation::factory()->create();

        $this->quarantineLocation = WarehouseLocation::factory()->create([
            'zone_id' => WarehouseZone::factory()->create(['zone_type' => WarehouseZoneType::Quarantine->value])->id,
        ]);

        $this->scrapLocation = WarehouseLocation::factory()->create([
            'zone_id' => WarehouseZone::factory()->create(['zone_type' => WarehouseZoneType::Scrap->value])->id,
        ]);

        foreach ([$this->goodLocation, $this->quarantineLocation, $this->scrapLocation] as $location) {
            StockLevel::create([
                'item_id'           => $this->item->id,
                'location_id'       => $location->id,
                'quantity'          => '10.000',
                'reserved_quantity' => '0.000',
                'weighted_avg_cost' => '5.0000',
                'lock_version'      => 0,
            ]);
        }
    }

    private function issueFrom(WarehouseLocation $location): void
    {
        $this->movements->move(new StockMovementInput(
            type: StockMovementType::MaterialIssue,
            itemId: $this->item->id,
            fromLocationId: $location->id,
            toLocationId: null,
            quantity: '1.000',
            unitCost: '5.00',
        ));
    }

    public function test_material_issue_from_normal_zone_succeeds(): void
    {
        $this->issueFrom($this->goodLocation);

        $level = StockLevel::where('item_id', $this->item->id)
            ->where('location_id', $this->goodLocation->id)
            ->firstOrFail();
        $this->assertSame('9.000', (string) $level->quantity);
    }

    public function test_material_issue_from_quarantine_zone_is_blocked(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('quarantine');

        $this->issueFrom($this->quarantineLocation);
    }

    public function test_material_issue_from_scrap_zone_is_blocked(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('scrap');

        $this->issueFrom($this->scrapLocation);
    }

    public function test_delivery_from_quarantine_zone_is_blocked(): void
    {
        $this->expectException(BusinessRuleException::class);

        $this->movements->move(new StockMovementInput(
            type: StockMovementType::Delivery,
            itemId: $this->item->id,
            fromLocationId: $this->quarantineLocation->id,
            toLocationId: null,
            quantity: '1.000',
            unitCost: '5.00',
        ));
    }

    public function test_transfer_out_of_quarantine_zone_is_allowed(): void
    {
        // Quarantine mechanics rely on transfers out (MRB release), so a
        // transfer must NOT trip the consumption guard.
        $this->movements->move(new StockMovementInput(
            type: StockMovementType::Transfer,
            itemId: $this->item->id,
            fromLocationId: $this->quarantineLocation->id,
            toLocationId: $this->goodLocation->id,
            quantity: '1.000',
            unitCost: '5.00',
        ));

        $level = StockLevel::where('item_id', $this->item->id)
            ->where('location_id', $this->quarantineLocation->id)
            ->firstOrFail();
        $this->assertSame('9.000', (string) $level->quantity);
    }

    public function test_reservation_in_quarantine_zone_is_blocked(): void
    {
        $this->expectException(BusinessRuleException::class);

        $this->movements->reserve($this->item->id, $this->quarantineLocation->id, '1.000');
    }

    public function test_reservation_in_scrap_zone_is_blocked(): void
    {
        $this->expectException(BusinessRuleException::class);

        $this->movements->reserve($this->item->id, $this->scrapLocation->id, '1.000');
    }
}

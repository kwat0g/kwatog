<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockCountItemStatus;
use App\Modules\Inventory\Enums\StockCountSessionStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Inventory\Models\StockCountSession;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\StockCountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockCountMakerCheckerTest extends TestCase
{
    use RefreshDatabase;

    public function test_counter_cannot_approve_their_own_variance(): void
    {
        $maker = User::factory()->create();
        $session = $this->makeSession($maker);
        $item = $this->countItem($session, StockCountItemStatus::Counted, $maker);

        try {
            app(StockCountService::class)->approveVariance($item->id, $maker);
            $this->fail('The count maker must not approve their own variance.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('different user', $e->getMessage());
        }

        $this->assertSame(StockCountItemStatus::Counted, $item->fresh()->status);
    }

    public function test_distinct_checker_is_recorded_on_variance_approval(): void
    {
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $session = $this->makeSession($maker);
        $item = $this->countItem($session, StockCountItemStatus::Counted, $maker);

        $verified = app(StockCountService::class)->approveVariance($item->id, $checker);

        $this->assertSame(StockCountItemStatus::Verified, $verified->status);
        $this->assertSame($checker->id, (int) $verified->verified_by);
    }

    public function test_session_cannot_complete_with_unrecorded_count_lines(): void
    {
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $session = $this->makeSession($maker);
        $item = $this->countItem($session, StockCountItemStatus::Pending, $maker);

        try {
            app(StockCountService::class)->completeSession($session->id, $checker);
            $this->fail('A count with pending lines must not be completed.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('every count line', strtolower($e->getMessage()));
        }

        $this->assertSame(StockCountSessionStatus::InProgress, $session->fresh()->status);
        $this->assertSame(StockCountItemStatus::Pending, $item->fresh()->status);
    }

    public function test_empty_stock_count_session_cannot_be_completed_as_a_successful_zero_count(): void
    {
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $session = $this->makeSession($maker);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Every count line must be recorded');

        app(StockCountService::class)->completeSession($session->id, $checker);
    }

    public function test_session_creator_cannot_complete_the_session(): void
    {
        $maker = User::factory()->create();
        $session = $this->makeSession($maker);
        $this->countItem($session, StockCountItemStatus::Counted, $maker);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('different user');

        app(StockCountService::class)->completeSession($session->id, $maker);
    }

    public function test_start_refreshes_the_snapshot_with_stock_received_after_draft_creation(): void
    {
        $maker = User::factory()->create();
        $zone = WarehouseZone::factory()->create();
        $location = WarehouseLocation::factory()->create(['zone_id' => $zone->id]);
        $item = Item::factory()->create();

        $session = app(StockCountService::class)->createSession([
            'title' => 'Late stock snapshot',
            'scope' => 'zone',
            'zone_id' => $zone->id,
        ], $maker);
        StockLevel::create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => '4.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '2.0000',
            'lock_version' => 0,
        ]);

        app(StockCountService::class)->startSession($session->id, $maker);

        $this->assertDatabaseHas('stock_count_items', [
            'session_id' => $session->id,
            'location_id' => $location->id,
            'item_id' => $item->id,
            'system_quantity' => '4.000',
        ]);
    }

    private function makeSession(User $maker): StockCountSession
    {
        return StockCountSession::create([
            'session_number' => 'SC-AUD-'.substr(uniqid(), -8),
            'title' => 'Audit maker-checker',
            'scope' => 'zone',
            'status' => StockCountSessionStatus::InProgress,
            'created_by' => $maker->id,
            'frozen_at' => now(),
        ]);
    }

    private function countItem(StockCountSession $session, StockCountItemStatus $status, User $maker): StockCountItem
    {
        return StockCountItem::create([
            'session_id' => $session->id,
            'location_id' => WarehouseLocation::factory()->create()->id,
            'item_id' => Item::factory()->create()->id,
            'system_quantity' => '10.000',
            'counted_quantity' => $status === StockCountItemStatus::Pending ? null : '5.000',
            'variance' => $status === StockCountItemStatus::Pending ? '0.000' : '-5.000',
            'variance_percent' => $status === StockCountItemStatus::Pending ? '0.00' : '50.00',
            'status' => $status,
            'counted_by' => $status === StockCountItemStatus::Pending ? null : $maker->id,
            'counted_at' => $status === StockCountItemStatus::Pending ? null : now(),
        ]);
    }
}

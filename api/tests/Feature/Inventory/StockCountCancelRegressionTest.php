<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockCountItemStatus;
use App\Modules\Inventory\Enums\StockCountSessionStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Inventory\Models\StockCountSession;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockAdjustmentService;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockCountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * P38 — StockCountService cancelSession/completeSession concurrency hardening.
 *
 * cancelSession() now locks the session row inside a transaction so it cannot
 * race a concurrent completion and mark a session Cancelled after its stock
 * adjustments already posted. completeSession() locks the session's items so a
 * count recorded mid-completion cannot be overwritten by a stale snapshot.
 */
class StockCountCancelRegressionTest extends TestCase
{
    use RefreshDatabase;

    private StockCountService $svc;
    private User $user;
    private WarehouseLocation $location;
    private Item $item;
    private StockCountSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([StockMovementCompleted::class]);

        $this->svc  = app(StockCountService::class);
        $this->user = User::factory()->create(['is_active' => true]);

        // Allow variance beyond the default tolerance so completion posts the
        // adjustment without supervisor sign-off.
        app(SettingsService::class)->set('inventory.stock_count.variance_tolerance_pct', 100);

        $this->item     = Item::factory()->create(['is_active' => true]);
        $this->location = WarehouseLocation::factory()->create();
        StockLevel::create([
            'item_id'           => $this->item->id,
            'location_id'       => $this->location->id,
            'quantity'          => '10.000',
            'reserved_quantity' => '0',
            'weighted_avg_cost' => '5.0000',
            'lock_version'      => 0,
        ]);

        $this->session = StockCountSession::create([
            'session_number'   => 'SC-CANCEL-' . substr(uniqid(), -6),
            'title'            => 'Cancel regression',
            'scope'            => 'zone',
            'zone_id'          => $this->location->zone_id,
            'status'           => StockCountSessionStatus::InProgress->value,
            'total_locations'  => 1,
            'created_by'       => $this->user->id,
            'frozen_at'        => now(),
        ]);

        StockCountItem::create([
            'session_id'       => $this->session->id,
            'location_id'      => $this->location->id,
            'item_id'          => $this->item->id,
            'system_quantity'  => '10.000',
            'status'           => StockCountItemStatus::Pending->value,
        ]);
    }

    private function countedItem(string $counted): StockCountItem
    {
        $item = $this->session->items()->first();
        $this->svc->recordCount($item->id, ['counted_quantity' => $counted], $this->user);
        return $item->fresh();
    }

    public function test_cancel_of_in_progress_session_posts_no_movements(): void
    {
        $this->countedItem('12.000'); // variance of +2 — would adjust if completed

        $session = $this->svc->cancelSession($this->session->id);

        $this->assertSame(StockCountSessionStatus::Cancelled, $session->status);
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_cancel_after_completion_is_blocked(): void
    {
        $this->countedItem('10.000'); // zero variance → completes cleanly
        $completed = $this->svc->completeSession($this->session->id, $this->user);
        $this->assertSame(StockCountSessionStatus::Completed, $completed->status);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('already completed');

        $this->svc->cancelSession($this->session->id);
    }

    public function test_complete_after_cancel_is_blocked(): void
    {
        $this->countedItem('12.000');
        $this->svc->cancelSession($this->session->id);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('must be in progress');

        $this->svc->completeSession($this->session->id, $this->user);
    }

    public function test_completion_posts_adjustment_for_recorded_variance(): void
    {
        $this->countedItem('12.000'); // +2 overage

        $completed = $this->svc->completeSession($this->session->id, $this->user);

        $this->assertSame(StockCountSessionStatus::Completed, $completed->status);
        $movement = StockMovement::query()
            ->where('movement_type', StockMovementType::AdjustmentIn->value)
            ->first();
        $this->assertNotNull($movement, 'variance overage must post an adjustment-in movement');
        $this->assertSame('2.000', (string) $movement->quantity);
        $this->assertSame('12.000', (string) StockLevel::query()
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->value('quantity'));
    }

    public function test_reconciliation_reloads_authoritative_count_and_rejects_replay(): void
    {
        $stale = $this->countedItem('12.000');
        $this->svc->recordCount($stale->id, ['counted_quantity' => '13.000'], $this->user);

        $adjustments = app(StockAdjustmentService::class);
        $movement = $adjustments->reconcileStockCountItem($stale, $this->user);

        $this->assertNotNull($movement);
        $this->assertSame('3.000', (string) $movement->quantity);
        $this->assertSame(StockCountItemStatus::Adjusted, $stale->fresh()->status);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('requires a counted item');
        $adjustments->reconcileStockCountItem($stale, $this->user);
    }
}

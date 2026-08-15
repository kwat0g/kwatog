<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\TransferOrderStatus;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\TransferOrder;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\TransferOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * P39 — TransferOrderService execute/cancel concurrency hardening.
 *
 * execute() and cancel() now lock the authoritative transfer-order row and
 * re-check the status inside the transaction, so concurrent requests cannot
 * both observe Pending (double-movement) and a cancelled order can never have
 * stock that already moved. execute() also backfills the movement's
 * reference_id so the ledger row traces to the transfer order.
 */
class TransferOrderRaceRegressionTest extends TestCase
{
    use RefreshDatabase;

    private TransferOrderService $svc;
    private WarehouseLocation $fromLoc;
    private WarehouseLocation $toLoc;
    private Item $item;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Auto-replenishment listener would try to create a PR — suppress it.
        Event::fake([StockMovementCompleted::class]);

        $this->svc  = app(TransferOrderService::class);
        $this->user = User::factory()->create(['is_active' => true]);

        $warehouse  = Warehouse::factory()->create();
        $rawZone    = WarehouseZone::factory()->create(['warehouse_id' => $warehouse->id, 'zone_type' => 'raw_materials']);
        $fgZone     = WarehouseZone::factory()->create(['warehouse_id' => $warehouse->id, 'zone_type' => 'finished_goods']);

        $this->fromLoc = WarehouseLocation::factory()->create(['zone_id' => $rawZone->id, 'is_active' => true]);
        $this->toLoc   = WarehouseLocation::factory()->create(['zone_id' => $fgZone->id, 'is_active' => true]);

        $this->item = Item::factory()->create(['is_active' => true]);
        StockLevel::create([
            'item_id'           => $this->item->id,
            'location_id'       => $this->fromLoc->id,
            'quantity'          => '100.000',
            'reserved_quantity' => '0',
            'weighted_avg_cost' => '25.0000',
            'lock_version'      => 0,
        ]);
    }

    private function pendingOrder(): TransferOrder
    {
        return $this->svc->create([
            'from_location_id' => $this->fromLoc->id,
            'to_location_id'   => $this->toLoc->id,
            'item_id'          => $this->item->id,
            'quantity'         => '30',
            'reason'           => 'Race regression ' . uniqid(),
        ], $this->user);
    }

    public function test_execute_posts_exactly_one_linked_movement(): void
    {
        $order = $this->svc->execute($this->pendingOrder()->id, $this->user);

        $this->assertSame(TransferOrderStatus::Transferred, $order->status);
        $this->assertSame(1, StockMovement::query()
            ->where('reference_type', 'stock_transfer')
            ->where('reference_id', $order->id)
            ->count());
        $this->assertSame('70.000', (string) StockLevel::query()
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->fromLoc->id)
            ->value('quantity'));
        $this->assertSame('30.000', (string) StockLevel::query()
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->toLoc->id)
            ->value('quantity'));
    }

    public function test_execute_again_is_blocked_after_transfer(): void
    {
        $order = $this->pendingOrder();
        $this->svc->execute($order->id, $this->user);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('not pending');

        $this->svc->execute($order->id, $this->user);
    }

    public function test_cancel_of_pending_order_posts_no_movement(): void
    {
        $order = $this->svc->cancel($this->pendingOrder()->id);

        $this->assertSame(TransferOrderStatus::Cancelled, $order->status);
        $this->assertSame(0, StockMovement::query()
            ->where('reference_type', 'stock_transfer')
            ->where('reference_id', $order->id)
            ->count());
    }

    public function test_cancel_after_execute_is_blocked(): void
    {
        $order = $this->pendingOrder();
        $this->svc->execute($order->id, $this->user);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Can only cancel pending');

        $this->svc->cancel($order->id);
    }

    public function test_execute_after_cancel_is_blocked(): void
    {
        $order = $this->pendingOrder();
        $this->svc->cancel($order->id);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('not pending');

        $this->svc->execute($order->id, $this->user);
    }
}

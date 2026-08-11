<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Models\Inspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceiveGoodsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create([
            'name' => 'Inventory-only receiver',
            'slug' => 'inventory-only-receiver-'.bin2hex(random_bytes(4)),
            'is_system' => false,
        ]);
        $permission = Permission::create([
            'name' => 'Create / Accept GRN',
            'slug' => 'inventory.grn.create',
            'module' => 'inventory',
        ]);
        $role->permissions()->attach($permission);

        $this->receiver = User::factory()->create([
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    public function test_inventory_only_receiver_cannot_submit_terminal_qc_result(): void
    {
        foreach (['passed', 'passed_with_remarks', 'failed'] as $result) {
            [$po, $poItem, $item, $location] = $this->makeOpenPurchaseOrder();
            $payload = $this->receivePayload($po, $poItem, $item, $location, $result);

            $this->actingAs($this->receiver, 'sanctum')
                ->postJson('/api/v1/inventory/receive-goods', $payload)
                ->assertForbidden();

            $this->assertSame(0, GoodsReceiptNote::query()->where('purchase_order_id', $po->id)->count());
            $this->assertSame(0, Inspection::query()->where('entity_type', 'grn')->count());
            $this->assertSame(0, StockMovement::query()->count());
            $this->assertSame(0, Bill::query()->where('purchase_order_id', $po->id)->count());
            $this->assertSame('0.00', (string) $poItem->fresh()->quantity_received);
        }
    }

    public function test_inventory_only_receiver_can_submit_pending_receiving(): void
    {
        [$po, $poItem, $item, $location] = $this->makeOpenPurchaseOrder();

        $this->actingAs($this->receiver, 'sanctum')
            ->postJson('/api/v1/inventory/receive-goods', $this->receivePayload(
                $po,
                $poItem,
                $item,
                $location,
                'pending',
            ))
            ->assertCreated()
            ->assertJsonPath('data.status', GrnStatus::PendingQc->value)
            ->assertJsonPath('qc_result', 'pending')
            ->assertJsonPath('stock_updated', false);

        $grn = GoodsReceiptNote::query()->where('purchase_order_id', $po->id)->firstOrFail();
        $this->assertSame(GrnStatus::PendingQc, $grn->status);
        $this->assertSame(1, Inspection::query()->where('entity_type', 'grn')->where('entity_id', $grn->id)->count());
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, Bill::query()->where('purchase_order_id', $po->id)->count());
    }

    public function test_service_backstop_rejects_terminal_qc_without_quality_permission(): void
    {
        [$po, $poItem, $item, $location] = $this->makeOpenPurchaseOrder();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('quality.inspections.manage');

        app(GrnService::class)->receiveWithQc(
            $po,
            [[
                'purchase_order_item_id' => $poItem->id,
                'item_id' => $item->id,
                'location_id' => $location->id,
                'quantity_received' => '10.000',
                'unit_cost' => '10.00',
            ]],
            ['received_date' => now()->toDateString()],
            ['result' => 'passed'],
            $this->receiver,
        );

        $this->assertSame(0, GoodsReceiptNote::query()->where('purchase_order_id', $po->id)->count());
    }

    /** @return array{0: PurchaseOrder, 1: PurchaseOrderItem, 2: Item, 3: WarehouseLocation} */
    private function makeOpenPurchaseOrder(): array
    {
        $item = Item::factory()->create(['is_active' => true]);
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'created_by' => $this->receiver->id,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Authorization test material',
            'quantity' => '100.000',
            'unit' => 'pcs',
            'unit_price' => '10.00',
            'total' => '1000.00',
            'quantity_received' => '0.000',
        ]);
        $location = WarehouseLocation::factory()->create();

        return [$po, $poItem, $item, $location];
    }

    /** @return array<string, mixed> */
    private function receivePayload(
        PurchaseOrder $po,
        PurchaseOrderItem $poItem,
        Item $item,
        WarehouseLocation $location,
        string $result,
    ): array {
        return [
            'purchase_order_id' => $po->hash_id,
            'items' => [[
                'purchase_order_item_id' => $poItem->hash_id,
                'item_id' => $item->hash_id,
                'location_id' => $location->hash_id,
                'quantity_received' => '10.000',
                'unit_cost' => '10.00',
            ]],
            'qc' => array_filter([
                'result' => $result,
                'failure_reason' => $result === 'failed' ? 'Failed receiving authorization test' : null,
            ], static fn ($value): bool => $value !== null),
        ];
    }
}

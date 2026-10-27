<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemUomConversion;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PurchaseOrderReceiptOverdueSignalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
    }

    public function test_receipt_overdue_badge_matches_list_with_status_quantity_uom_and_manila_date_rules(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 16:30:00', 'UTC'));

        try {
            $purchaser = User::factory()->create([
                'role_id' => Role::query()->where('slug', 'purchasing_officer')->value('id'),
            ]);
            $item = Item::factory()->create(['unit_of_measure' => 'KG']);
            $kg = Uom::query()->firstOrCreate(['code' => 'KG'], ['name' => 'Kilogram']);
            $bag = Uom::query()->firstOrCreate(['code' => 'BAG'], ['name' => 'Bag']);
            ItemUomConversion::query()->create([
                'item_id' => $item->id,
                'from_uom_id' => $bag->id,
                'to_uom_id' => $kg->id,
                'factor' => 25,
            ]);

            $qualifying = [];
            foreach (PurchaseOrderStatus::receivable() as $status) {
                $purchaseOrder = $this->po($purchaser, $status, '2026-10-24');
                $this->line($purchaseOrder, Item::factory()->create(['unit_of_measure' => 'PCS']), '10', 'PCS', '5');
                $qualifying[] = $purchaseOrder;
            }

            $alternateUnit = $this->po($purchaser, PurchaseOrderStatus::Sent, '2026-10-24');
            $this->line($alternateUnit, $item, '2', 'BAG', '49');
            $qualifying[] = $alternateUnit;

            $dueToday = $this->po($purchaser, PurchaseOrderStatus::Sent, '2026-10-26');
            $this->line($dueToday, Item::factory()->create(['unit_of_measure' => 'PCS']), '10', 'PCS', '0');

            $fullyAccepted = $this->po($purchaser, PurchaseOrderStatus::PartiallyReceived, '2026-10-24');
            $this->line($fullyAccepted, Item::factory()->create(['unit_of_measure' => 'PCS']), '10', 'PCS', '10');

            $alternateUnitFullyAccepted = $this->po($purchaser, PurchaseOrderStatus::Sent, '2026-10-24');
            $this->line($alternateUnitFullyAccepted, $item, '2', 'BAG', '50');

            $box = Uom::query()->firstOrCreate(['code' => 'BOX'], ['name' => 'Box']);
            $fractionalItem = Item::factory()->create(['unit_of_measure' => 'KG']);
            ItemUomConversion::query()->create([
                'item_id' => $fractionalItem->id,
                'from_uom_id' => $box->id,
                'to_uom_id' => $kg->id,
                'factor' => '1.000490',
            ]);
            $subPrecisionRemainder = $this->po($purchaser, PurchaseOrderStatus::Sent, '2026-10-24');
            $this->line($subPrecisionRemainder, $fractionalItem, '1', 'BOX', '1');

            foreach ([
                PurchaseOrderStatus::Draft,
                PurchaseOrderStatus::PendingApproval,
                PurchaseOrderStatus::Received,
                PurchaseOrderStatus::Closed,
                PurchaseOrderStatus::Cancelled,
                PurchaseOrderStatus::SupplierDeclined,
            ] as $status) {
                $purchaseOrder = $this->po($purchaser, $status, '2026-10-24');
                $this->line($purchaseOrder, Item::factory()->create(['unit_of_measure' => 'PCS']), '10', 'PCS', '0');
            }

            $this->po($purchaser, PurchaseOrderStatus::Sent, '2026-10-24');

            $badgeCount = $this->actingAs($purchaser, 'sanctum')
                ->getJson('/api/v1/dashboards/badges')
                ->assertOk()
                ->json('data.overdue_purchase_orders.count');
            $list = $this->actingAs($purchaser, 'sanctum')
                ->getJson('/api/v1/purchasing/purchase-orders?signal=receipt_overdue&per_page=100')
                ->assertOk();

            $this->assertSame($badgeCount, $list->json('meta.total'));
            $this->assertEqualsCanonicalizing(
                array_map(static fn (PurchaseOrder $po): string => $po->po_number, $qualifying),
                collect($list->json('data'))->pluck('po_number')->all(),
            );
            $this->assertSame(count($qualifying), $badgeCount);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_receipt_overdue_badge_and_list_use_the_same_purchase_order_visibility_scope(): void
    {
        $role = Role::query()->create([
            'name' => 'PO receipt reader',
            'slug' => 'po_receipt_reader',
            'is_system' => false,
        ]);
        $role->permissions()->sync(Permission::query()->where('slug', 'purchasing.view')->pluck('id')->all());
        $reader = User::factory()->create(['role_id' => $role->id]);
        $other = User::factory()->create();

        $own = $this->po($reader, PurchaseOrderStatus::Sent, '2026-10-24');
        $this->line($own, Item::factory()->create(['unit_of_measure' => 'PCS']), '10', 'PCS', '0');
        $hidden = $this->po($other, PurchaseOrderStatus::Sent, '2026-10-24');
        $this->line($hidden, Item::factory()->create(['unit_of_measure' => 'PCS']), '10', 'PCS', '0');

        $badgeCount = $this->actingAs($reader, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data.overdue_purchase_orders.count');
        $list = $this->actingAs($reader, 'sanctum')
            ->getJson('/api/v1/purchasing/purchase-orders?signal=receipt_overdue')
            ->assertOk();

        $this->assertSame(1, $badgeCount);
        $this->assertSame($badgeCount, $list->json('meta.total'));
        $this->assertSame($own->po_number, $list->json('data.0.po_number'));
        $this->actingAs($reader, 'sanctum')
            ->getJson('/api/v1/purchasing/purchase-orders/'.$hidden->hash_id)
            ->assertForbidden();
    }

    private function po(User $owner, PurchaseOrderStatus $status, string $expectedDate): PurchaseOrder
    {
        $purchaseOrder = PurchaseOrder::factory()->create([
            'created_by' => $owner->id,
            'expected_delivery_date' => $expectedDate,
        ]);
        $purchaseOrder->forceFill(['status' => $status])->save();

        return $purchaseOrder;
    }

    private function line(PurchaseOrder $purchaseOrder, Item $item, string $quantity, string $unit, string $accepted): void
    {
        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'item_id' => $item->id,
            'description' => $item->name,
            'quantity' => $quantity,
            'unit' => $unit,
            'unit_price' => '1.00',
            'total' => $quantity,
            'quantity_received' => $accepted,
            'quantity_accepted' => $accepted,
        ]);
    }
}

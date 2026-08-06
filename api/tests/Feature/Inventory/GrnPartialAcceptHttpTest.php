<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Models\Permission;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Models\Inspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F-09 — the SPA partial-accept path.
 *
 * GoodsReceiptNoteController::accept() accepts an `item_accepted_map` whose
 * keys are HashIDs (what the SPA sees from GrnItemResource). The controller
 * decodes each key to a raw line id before delegating to
 * GrnService::partialAccept(). These tests exercise the HTTP route end-to-end
 * with hash keys, matching what the frontend sends.
 */
class GrnPartialAcceptHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private GrnService $grnSvc;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['slug' => 'warehouse_staff'], ['name' => 'Warehouse Staff']);
        $permission = Permission::firstOrCreate(
            ['slug' => 'inventory.grn.create'],
            ['name' => 'Create GRN', 'module' => 'inventory'],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $this->user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $this->grnSvc = app(GrnService::class);
    }

    private function createTwoLineGrn(): GoodsReceiptNote
    {
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'created_by' => $this->user->id,
        ]);
        $lines = [];
        foreach (['100.000', '40.000'] as $i => $qty) {
            $item = Item::factory()->create(['is_active' => true]);
            $poItem = PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'item_id' => $item->id,
                'description' => "Material {$i}",
                'quantity' => $qty,
                'unit' => 'pcs',
                'unit_price' => '10.00',
                'total' => (string) ((float) $qty * 10),
                'quantity_received' => '0.000',
            ]);
            $lines[] = [
                'purchase_order_item_id' => $poItem->id,
                'item_id' => $item->id,
                'location_id' => WarehouseLocation::factory()->create()->id,
                'quantity_received' => $qty,
                'unit_cost' => '10.00',
            ];
        }

        $grn = $this->grnSvc->create($po, $lines, ['received_date' => now()->toDateString()], $this->user);
        $this->passInspections($grn);
        return $grn;
    }

    /**
     * GrnService::create() synchronously creates pending incoming inspections
     * (F-06 fail-closed gate). Mark them passed so accept/partial-accept
     * routes can proceed.
     */
    private function passInspections(GoodsReceiptNote $grn): void
    {
        Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->update(['status' => 'passed']);
    }

    public function test_partial_accept_with_hash_id_keys_marks_grn_partial_accepted(): void
    {
        $grn = $this->createTwoLineGrn();
        $this->assertSame(2, $grn->items->count());

        // Build the map exactly like the SPA: keys are hash ids, first line
        // fully accepted, second line half accepted.
        $map = [];
        foreach ($grn->items as $row) {
            $map[$row->hash_id] = $row->quantity_received === '100.000'
                ? '100.000'
                : '20.000';
        }

        $this->actingAs($this->user)
            ->patchJson("/api/v1/inventory/grn/{$grn->hash_id}/accept", ['item_accepted_map' => $map])
            ->assertOk()
            ->assertJsonPath('data.status', GrnStatus::PartialAccepted->value);

        $this->assertDatabaseHas('grn_items', [
            'goods_receipt_note_id' => $grn->id,
            'quantity_accepted' => '20.000',
        ]);
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('grn_items')
            ->where('goods_receipt_note_id', $grn->id)
            ->where('quantity_accepted', '100.000')
            ->count(), 'first line must stay fully accepted');
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('grn_items')
            ->where('goods_receipt_note_id', $grn->id)
            ->where('quantity_accepted', '20.000')
            ->count(), 'second line must record the partial 20.000 accepted');
    }

    public function test_partial_accept_with_all_full_quantities_marks_grn_accepted(): void
    {
        $grn = $this->createTwoLineGrn();

        $map = [];
        foreach ($grn->items as $row) {
            $map[$row->hash_id] = (string) $row->quantity_received;
        }

        $this->actingAs($this->user)
            ->patchJson("/api/v1/inventory/grn/{$grn->hash_id}/accept", ['item_accepted_map' => $map])
            ->assertOk()
            ->assertJsonPath('data.status', GrnStatus::Accepted->value);
    }

    public function test_partial_accept_rejects_all_zero_map(): void
    {
        $grn = $this->createTwoLineGrn();

        $map = [];
        foreach ($grn->items as $row) {
            $map[$row->hash_id] = '0.000';
        }

        $this->actingAs($this->user)
            ->patchJson("/api/v1/inventory/grn/{$grn->hash_id}/accept", ['item_accepted_map' => $map])
            ->assertStatus(422);
    }

    public function test_partial_accept_rejects_quantity_above_received(): void
    {
        $grn = $this->createTwoLineGrn();

        $map = [];
        foreach ($grn->items as $row) {
            $map[$row->hash_id] = $row->quantity_received === '100.000'
                ? '150.000'
                : (string) $row->quantity_received;
        }

        $this->actingAs($this->user)
            ->patchJson("/api/v1/inventory/grn/{$grn->hash_id}/accept", ['item_accepted_map' => $map])
            ->assertStatus(422);
    }
}

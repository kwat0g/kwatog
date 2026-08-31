<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Models\Inspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2026-09-01 re-audit (M041) — the receiving HTTP contract.
 *
 * Every test here exists because nothing in the repository exercised
 * `POST /api/v1/inventory/grn` over HTTP. The endpoint had returned 500 on every
 * request since 167de85e while 52 focused GRN tests stayed green, because they
 * all called GrnService directly and skipped the FormRequest.
 *
 * Covers, in order:
 *   R1 — the create endpoint answers 201 for a valid payload at all.
 *   R4 — soft-deleted parents fail validation (422) instead of 500.
 *   R3 — the money/quantity family: no 500 on 1e3/1e17/1e20, no silent
 *        rounding of a 4th decimal, on all three receiving surfaces.
 *   R2 — the incoming-QC coverage gate fails CLOSED for an archived item.
 */
class GrnHttpContractTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private GrnService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['slug' => 'warehouse_staff'], ['name' => 'Warehouse Staff']);
        foreach ([
            'inventory.grn.create' => 'inventory',
            'inventory.view' => 'inventory',
            'quality.inspections.manage' => 'quality',
        ] as $slug => $module) {
            $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'module' => $module]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $this->user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $this->svc = app(GrnService::class);
    }

    /** @return array{0:PurchaseOrder,1:PurchaseOrderItem,2:Item,3:WarehouseLocation} */
    private function fixture(string $orderedQty = '100.00'): array
    {
        $item = Item::factory()->create(['is_active' => true]);
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'created_by' => $this->user->id,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Material',
            'quantity' => $orderedQty,
            'unit' => 'pcs',
            'unit_price' => '10.00',
            'total' => '1000.00',
            'quantity_received' => '0.000',
        ]);

        return [$po, $poItem, $item, WarehouseLocation::factory()->create()];
    }

    private function storePayload(
        PurchaseOrder $po,
        PurchaseOrderItem $poItem,
        Item $item,
        WarehouseLocation $location,
        array $overrides = [],
    ): array {
        return [
            'purchase_order_id' => $po->hash_id,
            'items' => [array_merge([
                'purchase_order_item_id' => $poItem->hash_id,
                'item_id' => $item->hash_id,
                'location_id' => $location->hash_id,
                'quantity_received' => '5.000',
                'unit_cost' => '10.00',
            ], $overrides)],
        ];
    }

    // ── R1 — the endpoint works at all ───────────────────────────────────────

    public function test_store_grn_over_http_creates_a_pending_qc_receipt(): void
    {
        [$po, $poItem, $item, $location] = $this->fixture();

        $this->actingAs($this->user)
            ->postJson('/api/v1/inventory/grn', $this->storePayload($po, $poItem, $item, $location))
            ->assertCreated()
            ->assertJsonPath('data.status', GrnStatus::PendingQc->value);

        $this->assertSame(1, GoodsReceiptNote::query()->where('purchase_order_id', $po->id)->count());
        $this->assertDatabaseHas('grn_items', [
            'purchase_order_item_id' => $poItem->id,
            'quantity_received' => '5.000',
        ]);
    }

    public function test_store_grn_rejects_a_blocked_and_an_inactive_location_with_422(): void
    {
        [$po, $poItem, $item] = $this->fixture();

        $blocked = WarehouseLocation::factory()->create();
        $blocked->forceFill(['is_blocked' => true])->save();
        $this->actingAs($this->user)
            ->postJson('/api/v1/inventory/grn', $this->storePayload($po, $poItem, $item, $blocked))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.location_id');

        $inactive = WarehouseLocation::factory()->create(['is_active' => false]);
        $this->actingAs($this->user)
            ->postJson('/api/v1/inventory/grn', $this->storePayload($po, $poItem, $item, $inactive))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.location_id');

        $this->assertSame(0, GoodsReceiptNote::query()->count());
    }

    // ── R4 — archived parents are a 422, not a 500 ───────────────────────────

    public function test_store_grn_refuses_a_soft_deleted_purchase_order(): void
    {
        [$po, $poItem, $item, $location] = $this->fixture();
        $po->delete();

        $this->actingAs($this->user)
            ->postJson('/api/v1/inventory/grn', $this->storePayload($po, $poItem, $item, $location))
            ->assertStatus(422)
            ->assertJsonValidationErrors('purchase_order_id');
    }

    public function test_store_grn_refuses_a_soft_deleted_item(): void
    {
        [$po, $poItem, $item, $location] = $this->fixture();
        $item->delete();

        $this->actingAs($this->user)
            ->postJson('/api/v1/inventory/grn', $this->storePayload($po, $poItem, $item, $location))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.item_id');
    }

    public function test_store_grn_refuses_a_soft_deleted_location(): void
    {
        [$po, $poItem, $item, $location] = $this->fixture();
        $location->delete();

        $this->actingAs($this->user)
            ->postJson('/api/v1/inventory/grn', $this->storePayload($po, $poItem, $item, $location))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.location_id');
    }

    // ── R3 — money / quantity validation family ──────────────────────────────

    /**
     * is_numeric() accepts these; BCMath and PostgreSQL do not. Each one used to
     * be an uncaught ValueError or a 22003, i.e. a 500.
     *
     * @return array<string, array{0:string}>
     */
    public static function malformedNumbers(): array
    {
        return [
            'exponent' => ['1e3'],
            'big exponent' => ['1e17'],
            'huge exponent' => ['1e20'],
            'fourth decimal' => ['1.9999'],
            'fifth decimal' => ['10.00005'],
            'overflows the column' => ['9999999999999999.999'],
        ];
    }

    /** @dataProvider malformedNumbers */
    public function test_store_grn_refuses_a_malformed_quantity(string $value): void
    {
        [$po, $poItem, $item, $location] = $this->fixture();

        $this->actingAs($this->user)
            ->postJson('/api/v1/inventory/grn', $this->storePayload(
                $po, $poItem, $item, $location, ['quantity_received' => $value],
            ))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.quantity_received');

        $this->assertSame(0, GrnItem::query()->count());
    }

    /**
     * Cost is numeric(15,4), so a 4th decimal is legal there and only a 5th is
     * not — the one place where a quantity rule and a money rule must differ.
     *
     * @return array<string, array{0:string}>
     */
    public static function malformedCosts(): array
    {
        $cases = self::malformedNumbers();
        unset($cases['fourth decimal']);
        $cases['overflows the column'] = ['999999999999.9999'];

        return $cases;
    }

    /** @dataProvider malformedCosts */
    public function test_receive_with_qc_refuses_a_malformed_unit_cost(string $value): void
    {
        [$po, $poItem, $item, $location] = $this->fixture();

        $this->actingAs($this->user)
            ->postJson('/api/v1/inventory/receive-goods', [
                'purchase_order_id' => $po->hash_id,
                'items' => [[
                    'purchase_order_item_id' => $poItem->hash_id,
                    'item_id' => $item->hash_id,
                    'location_id' => $location->hash_id,
                    'quantity_received' => '5.000',
                    'unit_cost' => $value,
                ]],
                'qc' => ['result' => 'pending'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.unit_cost');

        $this->assertSame(0, GrnItem::query()->count());
    }

    /** @dataProvider malformedNumbers */
    public function test_finalize_draft_refuses_a_malformed_quantity(string $value): void
    {
        [$po, $poItem, , $location] = $this->fixture();
        $po->forceFill(['status' => PurchaseOrderStatus::Sent->value, 'sent_to_supplier_at' => now()])->save();
        $draft = $this->svc->createDraftForPo($po->fresh(), $this->user);
        $this->assertNotNull($draft);

        $this->actingAs($this->user)
            ->patchJson("/api/v1/inventory/grn/{$draft->hash_id}/finalize", [
                'items' => [[
                    'purchase_order_item_id' => $poItem->hash_id,
                    'location_id' => $location->hash_id,
                    'quantity_received' => $value,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.quantity_received');

        $this->assertSame('0.000', (string) $draft->items()->firstOrFail()->quantity_received);
    }

    /** @dataProvider malformedNumbers */
    public function test_accept_refuses_a_malformed_accepted_quantity(string $value): void
    {
        [$po, $poItem, $item, $location] = $this->fixture();
        $grn = $this->svc->create($po, [[
            'purchase_order_item_id' => $poItem->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_received' => '10.000',
            'unit_cost' => '10.00',
        ]], ['received_date' => now()->toDateString()], $this->user);
        Inspection::query()
            ->where('entity_type', 'grn')->where('entity_id', $grn->id)
            ->update(['status' => 'passed']);
        $line = $grn->items->firstOrFail();

        $this->actingAs($this->user)
            ->patchJson("/api/v1/inventory/grn/{$grn->hash_id}/accept", [
                'item_accepted_map' => [$line->hash_id => $value],
            ])
            ->assertStatus(422);

        $this->assertSame('0.000', (string) $line->fresh()->quantity_accepted, 'no stock may move');
        $this->assertSame(GrnStatus::PendingQc, $grn->fresh()->status);
    }

    // ── R2 — the QC coverage gate must fail CLOSED for an archived item ──────

    /**
     * The measured fail-open: with `modules.accounting` off, an archived item
     * made every line ineligible, so a receipt with zero inspection rows was
     * accepted and moved stock. The control (live item) was refused, which is
     * what pins the cause on the soft delete rather than on the setting.
     */
    public function test_incoming_qc_gate_holds_when_the_received_item_is_archived(): void
    {
        DB::table('settings')->where('key', 'modules.accounting')->update(['value' => 'false']);
        app()->forgetInstance(SettingsService::class);
        Cache::flush();

        [$po, $poItem, $item, $location] = $this->fixture();
        $grn = $this->svc->create($po, [[
            'purchase_order_item_id' => $poItem->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_received' => '10.000',
            'unit_cost' => '10.00',
        ]], ['received_date' => now()->toDateString()], $this->user);

        // Model the F-06 anomaly the gate exists for: the inspections never
        // materialised. Then archive the item behind the receipt.
        Inspection::query()->where('entity_type', 'grn')->where('entity_id', $grn->id)->delete();
        DB::table('goods_receipt_notes')->where('id', $grn->id)->update(['qc_inspection_id' => null]);
        $item->delete();

        try {
            $this->svc->accept($grn->fresh(), $this->user);
            $this->fail('an archived item must not waive the incoming-QC requirement');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('no incoming inspection records', $e->getMessage());
        }

        $this->assertSame(GrnStatus::PendingQc, $grn->fresh()->status);
        $this->assertNull(
            StockLevel::query()->where('item_id', $item->id)->where('location_id', $location->id)->first(),
            'no stock may exist for a receipt that never passed incoming QC',
        );
        $this->assertSame(0, DB::table('stock_movements')
            ->where('reference_type', 'goods_receipt_note')->where('reference_id', $grn->id)->count());
    }

    /** Control for the test above — the live-item path was already correct. */
    public function test_incoming_qc_gate_holds_for_a_live_item_with_no_inspections(): void
    {
        DB::table('settings')->where('key', 'modules.accounting')->update(['value' => 'false']);
        app()->forgetInstance(SettingsService::class);
        Cache::flush();

        [$po, $poItem, $item, $location] = $this->fixture();
        $grn = $this->svc->create($po, [[
            'purchase_order_item_id' => $poItem->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_received' => '10.000',
            'unit_cost' => '10.00',
        ]], ['received_date' => now()->toDateString()], $this->user);
        Inspection::query()->where('entity_type', 'grn')->where('entity_id', $grn->id)->delete();
        DB::table('goods_receipt_notes')->where('id', $grn->id)->update(['qc_inspection_id' => null]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('no incoming inspection records');

        $this->svc->accept($grn->fresh(), $this->user);
    }

    /**
     * An item archived AFTER a genuine QC pass must still post its GL entry
     * rather than dying on a firstOrFail() the receiving clerk cannot act on.
     */
    public function test_accepting_a_passed_receipt_whose_item_was_archived_still_posts(): void
    {
        [$po, $poItem, $item, $location] = $this->fixture();
        $grn = $this->svc->create($po, [[
            'purchase_order_item_id' => $poItem->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_received' => '10.000',
            'unit_cost' => '10.00',
        ]], ['received_date' => now()->toDateString()], $this->user);
        Inspection::query()
            ->where('entity_type', 'grn')->where('entity_id', $grn->id)
            ->update(['status' => 'passed']);
        $item->delete();

        $accepted = $this->svc->accept($grn->fresh(), $this->user);

        $this->assertSame(GrnStatus::Accepted, $accepted->status);
        $this->assertSame('10.000', (string) StockLevel::query()
            ->where('item_id', $item->id)->where('location_id', $location->id)
            ->firstOrFail()->quantity);
    }
}

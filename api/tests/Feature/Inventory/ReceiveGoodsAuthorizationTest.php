<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\CRM\Models\Product;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use Database\Seeders\ChartOfAccountsSeeder;
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

    public function test_terminal_inline_qc_without_sampling_evidence_rolls_back_the_receipt(): void
    {
        [$po, $poItem, $item, $location] = $this->makeOpenPurchaseOrder();
        $qcRole = Role::firstOrCreate(['slug' => 'qc_inline_test'], ['name' => 'QC Inline Test']);
        $permission = Permission::firstOrCreate(['slug' => 'quality.inspections.manage'], ['name' => 'Quality manage', 'module' => 'quality']);
        $qcRole->permissions()->syncWithoutDetaching([$permission->id]);
        $maker = User::factory()->create(['role_id' => $qcRole->id, 'is_active' => true]);
        $checks = $this->defaultChecks();
        try {
            app(GrnService::class)->receiveWithQc($po, [[
                'purchase_order_item_id' => $poItem->id, 'item_id' => $item->id,
                'location_id' => $location->id, 'quantity_received' => '10.000', 'unit_cost' => '10.00',
            ]], ['received_date' => now()->toDateString()], ['result' => 'passed', 'checks' => $checks], $maker);
            $this->fail('Inline receiving must not fabricate sampling evidence.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('planned sample', $e->getMessage());
        }
        $this->assertSame(0, GoodsReceiptNote::query()->count());
        $this->assertSame(0, Inspection::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_terminal_inline_qc_requires_every_checklist_decision_and_rolls_back(): void
    {
        [$po, $poItem, $item, $location] = $this->makeOpenPurchaseOrder();
        $qcRole = Role::firstOrCreate(['slug' => 'qc_check_test'], ['name' => 'QC Check Test']);
        $permission = Permission::firstOrCreate(['slug' => 'quality.inspections.manage'], ['name' => 'Quality manage', 'module' => 'quality']);
        $qcRole->permissions()->syncWithoutDetaching([$permission->id]);
        $maker = User::factory()->create(['role_id' => $qcRole->id, 'is_active' => true]);
        $checks = $this->defaultChecks();
        array_pop($checks);

        try {
            app(GrnService::class)->receiveWithQc($po, [[
                'purchase_order_item_id' => $poItem->id, 'item_id' => $item->id,
                'location_id' => $location->id, 'quantity_received' => '10.000', 'unit_cost' => '10.00',
            ]], ['received_date' => now()->toDateString()], [
                'result' => 'passed',
                'checks' => $checks,
                'coverage_confirmed' => true,
                'inspected_quantity' => 10,
                'sample_defect_count' => 0,
                'critical_defect' => false,
                'defects_removed_confirmed' => false,
            ], $maker);
            $this->fail('Inline QC must reject an incomplete checklist.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('every inspection requirement', $e->getMessage());
        }

        $this->assertSame(0, GoodsReceiptNote::query()->count());
        $this->assertSame(0, Inspection::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame('0.00', (string) $poItem->fresh()->quantity_received);
    }

    public function test_terminal_receive_goods_api_records_checks_and_holds_stock_for_checker(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        [$po, $poItem, $item, $location] = $this->makeOpenPurchaseOrder();

        $makerRole = Role::create(['name' => 'Inline QC Maker', 'slug' => 'inline-qc-maker-'.bin2hex(random_bytes(4)), 'is_system' => false]);
        $makerPermissions = [
            Permission::query()->where('slug', 'inventory.grn.create')->firstOrFail()->id,
            Permission::firstOrCreate(['slug' => 'quality.inspections.manage'], ['name' => 'Quality manage', 'module' => 'quality'])->id,
        ];
        $makerRole->permissions()->attach($makerPermissions);
        $maker = User::factory()->create(['role_id' => $makerRole->id, 'is_active' => true]);

        $checkerRole = Role::create(['name' => 'Inline QC Checker', 'slug' => 'inline-qc-checker-'.bin2hex(random_bytes(4)), 'is_system' => false]);
        $checkerRole->permissions()->attach([
            Permission::firstOrCreate(['slug' => 'quality.inspections.review'], ['name' => 'Quality review', 'module' => 'quality'])->id,
            Permission::firstOrCreate(['slug' => 'quality.inspections.view'], ['name' => 'Quality view', 'module' => 'quality'])->id,
        ]);
        $checker = User::factory()->create(['role_id' => $checkerRole->id, 'is_active' => true]);

        $payload = $this->receivePayload($po, $poItem, $item, $location, 'passed');
        $payload['qc'] = [
            'result' => 'passed',
            'coverage_confirmed' => true,
            'inspected_quantity' => 10,
            'sample_defect_count' => 0,
            'critical_defect' => false,
            'defects_removed_confirmed' => false,
            'checks' => $this->defaultChecks(),
        ];
        $this->actingAs($maker, 'sanctum')
            ->postJson('/api/v1/inventory/receive-goods', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', GrnStatus::PendingQc->value)
            ->assertJsonPath('qc_result', 'passed')
            ->assertJsonPath('stock_updated', false);

        $grn = GoodsReceiptNote::query()->where('purchase_order_id', $po->id)->firstOrFail();
        $inspection = Inspection::query()->where('entity_type', 'grn')->where('entity_id', $grn->id)->firstOrFail();
        $this->assertSame(InspectionStatus::AwaitingReview->value, $inspection->status->value);
        $this->assertSame('10.00', (string) $poItem->fresh()->quantity_received);
        $this->assertSame('0.000', (string) $grn->items()->firstOrFail()->quantity_accepted);
        $this->assertTrue($inspection->measurements->every(fn ($row) => $row->item_result === 'passed'));
        $this->assertDatabaseHas('inspection_result_authors', [
            'inspection_id' => $inspection->id,
            'user_id' => $maker->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $checker->id,
            'type' => 'quality.inspection_awaiting_review',
        ]);
        $this->assertSame(0, StockMovement::query()->where('reference_type', 'goods_receipt_note')
            ->where('reference_id', $grn->id)->count());
        $this->assertNull($grn->journal_entry_id);

        $this->actingAs($checker, 'sanctum')
            ->patchJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/review', ['decision' => 'passed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'passed');

        $this->assertSame('passed', $inspection->fresh()->status->value);
        $this->assertSame($checker->id, $inspection->fresh()->reviewed_by);
        $this->assertSame(GrnStatus::Accepted, $grn->fresh()->status);
        $this->assertSame('10.000', (string) $grn->items()->firstOrFail()->fresh()->quantity_accepted);
        $this->assertSame(1, StockMovement::query()->where('reference_type', 'goods_receipt_note')
            ->where('reference_id', $grn->id)->count());
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

    public function test_pending_receipt_rejects_non_quality_inspector_delegate_without_writes(): void
    {
        [$po, $poItem, $item, $location] = $this->makeOpenPurchaseOrder();
        $product = Product::factory()->create();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('active Quality user with quality.inspections.manage');
        try {
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
                ['result' => 'pending', 'product_id' => $product->hash_id, 'inspector_id' => $this->receiver->hash_id],
                $this->receiver,
            );
        } finally {
            $this->assertSame(0, GoodsReceiptNote::query()->count());
            $this->assertSame(0, Inspection::query()->count());
            $this->assertSame(0, StockMovement::query()->count());
        }
    }

    public function test_single_screen_receiving_rejects_unsupported_dispositions_without_writes(): void
    {
        foreach (['use_under_concession', 'partial_accept'] as $disposition) {
            [$po, $poItem, $item, $location] = $this->makeOpenPurchaseOrder();
            $payload = $this->receivePayload($po, $poItem, $item, $location, 'pending');
            $payload['qc']['disposition'] = $disposition;

            $this->actingAs($this->receiver, 'sanctum')
                ->postJson('/api/v1/inventory/receive-goods', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('qc.disposition');

            $this->assertSame(0, GoodsReceiptNote::query()->where('purchase_order_id', $po->id)->count());
            $this->assertSame(0, GrnItem::query()->count());
            $this->assertSame(0, Inspection::query()->where('entity_type', 'grn')->count());
            $this->assertSame(0, StockMovement::query()->count());
            $this->assertSame(0, Bill::query()->where('purchase_order_id', $po->id)->count());
            $this->assertSame('0.00', (string) $poItem->fresh()->quantity_received);
        }
    }

    public function test_service_backstop_rejects_unsupported_single_screen_dispositions_without_writes(): void
    {
        [$po, $poItem, $item, $location] = $this->makeOpenPurchaseOrder();

        try {
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
                ['result' => 'pending', 'disposition' => 'partial_accept'],
                $this->receiver,
            );
            $this->fail('The service must refuse unsupported dispositions before creating a receipt.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(
                'QC disposition is not implemented for single-screen receiving. Use the GRN partial-accept or supplier-return workflow instead.',
                $e->getMessage(),
            );
        }

        $this->assertSame(0, GoodsReceiptNote::query()->where('purchase_order_id', $po->id)->count());
        $this->assertSame(0, GrnItem::query()->count());
        $this->assertSame(0, Inspection::query()->where('entity_type', 'grn')->count());
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, Bill::query()->where('purchase_order_id', $po->id)->count());
        $this->assertSame('0.00', (string) $poItem->fresh()->quantity_received);
    }

    public function test_service_backstop_rejects_terminal_qc_without_quality_permission(): void
    {
        [$po, $poItem, $item, $location] = $this->makeOpenPurchaseOrder();

        $blocked = false;
        try {
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
        } catch (BusinessRuleException $e) {
            $blocked = true;
            $this->assertStringContainsString('quality.inspections.manage', $e->getMessage());
        }

        $this->assertTrue($blocked);
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

    private function defaultChecks(): array
    {
        return array_map(
            static fn (array $check): array => ['label' => $check['parameter_name'], 'passed' => true],
            (array) app(SettingsService::class)->get('quality.incoming.default_checklist', []),
        );
    }
}

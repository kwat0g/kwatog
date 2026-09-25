<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Common\Support\HashIdFilter;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemUomConversion;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\ReturnManagement\Models\ReturnCase;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReturnCaseSupplierQuantityTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        $this->buyer = User::factory()->create(['role_id' => Role::query()->where('slug', 'purchasing_officer')->value('id')]);
        $this->actingAs($this->buyer);
    }

    private function receipt(): array
    {
        $item = Item::factory()->create(['unit_of_measure' => 'KG']);
        $kg = Uom::firstOrCreate(['code' => 'KG'], ['name' => 'Kilogram']);
        $bag = Uom::firstOrCreate(['code' => 'BAG'], ['name' => 'Bag']);
        ItemUomConversion::create(['item_id' => $item->id, 'from_uom_id' => $bag->id, 'to_uom_id' => $kg->id, 'factor' => '5.000000']);
        $po = PurchaseOrder::factory()->create(['status' => 'partially_received', 'created_by' => $this->buyer->id]);
        $line = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'item_id' => $item->id, 'description' => 'Resin',
            'quantity' => '2', 'unit' => 'BAG', 'unit_price' => '100', 'total' => '200',
            'quantity_received' => '6', 'quantity_accepted' => '6',
        ]);
        $grn = GoodsReceiptNote::factory()->create(['purchase_order_id' => $po->id, 'vendor_id' => $po->vendor_id, 'received_by' => $this->buyer->id, 'status' => 'accepted']);
        $grnLine = GrnItem::create([
            'goods_receipt_note_id' => $grn->id, 'purchase_order_item_id' => $line->id,
            'item_id' => $item->id, 'location_id' => WarehouseLocation::factory()->create()->id,
            'quantity_received' => '6', 'quantity_accepted' => '6', 'unit_cost' => '20',
        ]);

        return [$po, $line, $grn, $grnLine];
    }

    private function report($source, $line, string $expected, string $received, string $defective = '0'): array
    {
        return [
            'source_kind' => $source instanceof PurchaseOrder ? 'purchase_order' : 'grn',
            'source_id' => $source->hash_id, 'request_key' => (string) Str::uuid(),
            'description' => 'Supplier promised shipment did not match receipt.', 'preferred_resolution' => 'redelivery',
            'lines' => [['source_line_id' => $line->hash_id, 'expected_quantity' => $expected, 'received_quantity' => $received, 'defective_quantity' => $defective]],
        ];
    }

    private function supplierRedeliveryCase(): array
    {
        [$po, $poLine, $grn, $grnLine] = $this->receipt();
        $priorReceipt = $this->acceptedReceipt($po, $poLine, '2.000');
        $priorReceipt->forceFill(['created_at' => now()->subMinute(), 'updated_at' => now()->subMinute()])->save();
        $caseHash = $this->postJson('/api/v1/return-management/cases', $this->report($grn, $grnLine, '6', '6', '2'))
            ->assertCreated()->json('data.id');
        $caseId = HashIdFilter::decode($caseHash, ReturnCase::class);
        $case = ReturnCase::query()->findOrFail($caseId);
        $path = '/api/v1/return-management/cases/'.$caseHash.'/actions';
        $this->postJson($path, ['action' => 'agree', 'resolution' => 'redelivery', 'message' => 'Replace the defective received material.'])
            ->assertOk();
        $this->postJson($path, ['action' => 'create_return'])->assertOk();
        $case->refresh();

        return [$caseHash, $case, $po, $poLine, $grn, $grnLine, $priorReceipt, $case->returnRequest()->firstOrFail()];
    }

    private function orderForVendorAndItem(PurchaseOrder $source, Item $item): array
    {
        $order = PurchaseOrder::factory()->create(['vendor_id' => $source->vendor_id, 'created_by' => $this->buyer->id]);
        $order->forceFill(['status' => 'sent'])->save();
        $line = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id, 'item_id' => $item->id, 'description' => 'Resin',
            'quantity' => '2', 'unit' => 'KG', 'unit_price' => '100', 'total' => '200',
            'quantity_received' => '0', 'quantity_accepted' => '0',
        ]);

        return [$order, $line];
    }

    private function acceptedReceipt(PurchaseOrder $order, PurchaseOrderItem $poLine, string $quantity, string $status = 'accepted'): GoodsReceiptNote
    {
        $grn = GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $order->id, 'vendor_id' => $order->vendor_id,
            'received_by' => $this->buyer->id, 'status' => $status,
        ]);
        GrnItem::create([
            'goods_receipt_note_id' => $grn->id, 'purchase_order_item_id' => $poLine->id,
            'item_id' => $poLine->item_id, 'location_id' => WarehouseLocation::factory()->create()->id,
            'quantity_received' => $quantity, 'quantity_accepted' => $quantity, 'unit_cost' => '20',
        ]);

        return $grn;
    }

    public function test_po_missing_quantity_uses_only_unreceived_base_units(): void
    {
        [$po, $line] = $this->receipt();
        $this->getJson('/api/v1/return-management/cases/source-options?source_kind=purchase_order&source_id='.$po->hash_id)
            ->assertOk()->assertJsonPath('data.lines.0.maximum_quantity', '4.000');
        $this->postJson('/api/v1/return-management/cases', $this->report($po, $line, '10', '0'))->assertUnprocessable();
        $this->postJson('/api/v1/return-management/cases', $this->report($po, $line, '4', '0'))
            ->assertCreated()->assertJsonPath('data.lines.0.missing_quantity', '4.000');
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('return_requests', 0);
        $this->assertDatabaseCount('purchase_orders', 1);
    }

    public function test_po_and_grn_share_one_shortage_budget(): void
    {
        [$po, $line, $grn, $grnLine] = $this->receipt();
        $this->postJson('/api/v1/return-management/cases', $this->report($po, $line, '4', '0'))->assertCreated();
        $this->postJson('/api/v1/return-management/cases', $this->report($grn, $grnLine, '10', '6'))->assertUnprocessable();
        $this->assertDatabaseCount('return_cases', 1);
    }

    public function test_partial_receipt_does_not_automatically_claim_missing_goods(): void
    {
        [$po, $line, $grn, $grnLine] = $this->receipt();
        $this->postJson('/api/v1/return-management/cases', $this->report($grn, $grnLine, '6', '6', '1'))
            ->assertCreated()->assertJsonPath('data.lines.0.missing_quantity', '0.000')
            ->assertJsonPath('data.lines.0.defective_quantity', '1.000');
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_supplier_can_acknowledge_only_its_own_cases(): void
    {
        [$po, $line] = $this->receipt();
        $case = $this->postJson('/api/v1/return-management/cases', $this->report($po, $line, '4', '0'))->assertCreated()->json('data.id');
        $supplier = SupplierPortalUser::create([
            'vendor_id' => $po->vendor_id, 'name' => 'Supplier', 'email' => 'supplier@test.local',
            'password' => bcrypt('Password1!'), 'is_active' => true, 'password_changed_at' => now(),
        ]);
        $this->actingAs($supplier, 'supplier_portal')->postJson('/api/v1/b2b/supplier/problems/'.$case.'/actions', [
            'action' => 'acknowledge', 'message' => 'We will deliver the remaining four kilograms.',
        ])->assertOk()->assertJsonPath('data.events.1.actor_type', 'supplier');
        $otherPo = PurchaseOrder::factory()->create();
        $supplier->update(['vendor_id' => $otherPo->vendor_id]);
        $this->actingAs($supplier->fresh(), 'supplier_portal')->getJson('/api/v1/b2b/supplier/problems/'.$case)->assertForbidden();
    }

    public function test_unbilled_shortage_cannot_credit_the_received_goods_bill(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        [$po, $line, $grn, $grnLine] = $this->receipt();
        $bill = Bill::factory()->create([
            'purchase_order_id' => $po->id, 'goods_receipt_note_id' => $grn->id,
            'vendor_id' => $po->vendor_id, 'status' => 'unpaid', 'created_by' => $this->buyer->id,
        ]);
        $bill->items()->create([
            'item_id' => $line->item_id,
            'expense_account_id' => Account::query()->where('type', 'expense')->value('id'),
            'description' => 'Actually received resin', 'quantity' => '6', 'unit' => 'KG', 'unit_price' => '20', 'total' => '120',
        ]);
        $case = $this->postJson('/api/v1/return-management/cases', $this->report($grn, $grnLine, '10', '6'))->assertCreated()->json('data.id');
        $path = '/api/v1/return-management/cases/'.$case.'/actions';
        $this->postJson($path, ['action' => 'agree', 'resolution' => 'credit', 'message' => 'Review the shortage for credit.'])->assertOk();
        $finance = User::factory()->create(['role_id' => Role::query()->where('slug', 'finance_officer')->value('id')]);
        $response = $this->actingAs($finance)->postJson($path, ['action' => 'create_credit'])->assertUnprocessable();
        $this->assertStringContainsString('not billed', $response->getContent());
        $this->assertDatabaseCount('credit_notes', 0);
    }

    public function test_supplier_resolution_links_require_rma_lineage_and_allow_incremental_receipts(): void
    {
        [$caseHash, $case, $sourcePo, $sourceLine, $sourceGrn, , $priorReceipt, $rma] = $this->supplierRedeliveryCase();
        [$replacementPo, $replacementLine] = $this->orderForVendorAndItem($sourcePo, $sourceLine->item);
        $rma->update(['replacement_purchase_order_id' => $replacementPo->id]);

        [$unrelatedPo, $unrelatedLine] = $this->orderForVendorAndItem($sourcePo, $sourceLine->item);
        $unrelatedReceipt = $this->acceptedReceipt($unrelatedPo, $unrelatedLine, '2.000');
        $otherOriginalLine = PurchaseOrderItem::create([
            'purchase_order_id' => $sourcePo->id, 'item_id' => $sourceLine->item_id, 'description' => 'Resin alternate line',
            'quantity' => '2', 'unit' => 'KG', 'unit_price' => '100', 'total' => '200',
            'quantity_received' => '0', 'quantity_accepted' => '0',
        ]);
        $wrongSourceLineReceipt = $this->acceptedReceipt($sourcePo, $otherOriginalLine, '2.000');
        $insufficientReceipt = $this->acceptedReceipt($replacementPo, $replacementLine, '1.000');
        $pendingReceipt = $this->acceptedReceipt($replacementPo, $replacementLine, '2.000', 'pending_qc');

        $optionsPath = '/api/v1/return-management/cases/'.$caseHash.'/resolution-options';
        $options = $this->getJson($optionsPath)->assertOk()->json('data');
        $this->assertSame([$replacementPo->hash_id], array_column($options['purchase_orders'], 'id'));
        $this->assertSame([$insufficientReceipt->hash_id], array_column($options['goods_receipts'], 'id'));
        $this->assertNotContains($unrelatedPo->hash_id, array_column($options['purchase_orders'], 'id'));
        $this->assertNotContains($sourceGrn->hash_id, array_column($options['goods_receipts'], 'id'));
        $this->assertNotContains($priorReceipt->hash_id, array_column($options['goods_receipts'], 'id'));
        $this->assertNotContains($unrelatedReceipt->hash_id, array_column($options['goods_receipts'], 'id'));
        $this->assertNotContains($wrongSourceLineReceipt->hash_id, array_column($options['goods_receipts'], 'id'));
        $this->assertNotContains($pendingReceipt->hash_id, array_column($options['goods_receipts'], 'id'));

        $path = '/api/v1/return-management/cases/'.$caseHash.'/actions';
        $this->postJson($path, ['action' => 'link_resolution', 'replacement_purchase_order_id' => $unrelatedPo->hash_id])
            ->assertUnprocessable();
        $this->postJson($path, ['action' => 'link_resolution', 'resolution_goods_receipt_note_id' => $unrelatedReceipt->hash_id])
            ->assertUnprocessable();
        $this->postJson($path, ['action' => 'link_resolution', 'resolution_goods_receipt_note_id' => $wrongSourceLineReceipt->hash_id])
            ->assertUnprocessable();
        $this->assertNull($case->fresh()->replacement_purchase_order_id);
        $this->assertNull($case->fresh()->resolution_goods_receipt_note_id);

        $this->postJson($path, [
            'action' => 'link_resolution', 'replacement_purchase_order_id' => $replacementPo->hash_id,
            'resolution_goods_receipt_note_id' => $insufficientReceipt->hash_id,
        ])->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.lines.0.redelivered_quantity', '1.000')
            ->assertJsonPath('data.lines.0.remaining_redelivery_quantity', '1.000')
            ->assertJsonCount(1, 'data.resolution_receipts');
        $this->postJson($path, [
            'action' => 'link_resolution', 'resolution_goods_receipt_note_id' => $insufficientReceipt->hash_id,
        ])->assertOk()->assertJsonPath('data.lines.0.redelivered_quantity', '1.000')
            ->assertJsonCount(1, 'data.resolution_receipts');
        $this->postJson($path, ['action' => 'resolve'])->assertUnprocessable();

        $validReceipt = $this->acceptedReceipt($replacementPo, $replacementLine, '1.000', 'partial_accepted');
        $eligibleOptions = $this->getJson($optionsPath)->assertOk()->json('data');
        $this->assertSame([$validReceipt->hash_id], array_column($eligibleOptions['goods_receipts'], 'id'));
        $completion = $this->postJson($path, [
            'action' => 'link_resolution', 'resolution_goods_receipt_note_ids' => [$validReceipt->hash_id],
        ])->assertOk()->assertJsonPath('data.lines.0.redelivered_quantity', '2.000')
            ->assertJsonPath('data.lines.0.remaining_redelivery_quantity', '0.000')
            ->assertJsonCount(2, 'data.resolution_receipts');
        $this->assertSame(
            [$insufficientReceipt->hash_id, $validReceipt->hash_id],
            array_column($completion->json('data.resolution_receipts'), 'id'),
        );
        $this->assertSame($replacementPo->id, $case->fresh()->replacement_purchase_order_id);
    }
}

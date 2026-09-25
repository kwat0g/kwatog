<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReturnCaseSplitReceiptTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        $this->manager = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'purchasing_officer')->value('id'),
        ]);
        $this->actingAs($this->manager);
    }

    /** @param array<int, string> $quantities */
    private function order(array $quantities, ?Vendor $vendor = null, ?Item $item = null): array
    {
        $vendor ??= Vendor::factory()->create();
        $item ??= Item::factory()->create(['unit_of_measure' => 'KG']);
        $po = PurchaseOrder::factory()->create([
            'vendor_id' => $vendor->id,
            'created_by' => $this->manager->id,
        ]);
        $po->forceFill(['status' => 'sent'])->save();
        $lines = collect($quantities)->map(fn (string $quantity) => PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => $item->name,
            'quantity' => $quantity,
            'unit' => 'KG',
            'unit_price' => '10.00',
            'total' => bcmul($quantity, '10.00', 2),
            'quantity_received' => '0.000',
            'quantity_accepted' => '0.000',
        ]));

        return [$po, $lines, $item, $vendor];
    }

    /** @param array<int, array{0: PurchaseOrderItem, 1: string}> $claims */
    private function submitCase(PurchaseOrder $po, array $claims): string
    {
        $payload = [
            'source_kind' => 'purchase_order',
            'source_id' => $po->hash_id,
            'request_key' => (string) Str::uuid(),
            'description' => 'Agreed supplier redelivery is arriving in separate shipments.',
            'preferred_resolution' => 'redelivery',
            'lines' => array_map(fn (array $claim): array => [
                'source_line_id' => $claim[0]->hash_id,
                'expected_quantity' => $claim[1],
                'received_quantity' => '0.000',
                'defective_quantity' => '0.000',
                'reason' => 'Missing from the agreed shipment.',
            ], $claims),
        ];
        $caseHash = $this->postJson('/api/v1/return-management/cases', $payload)
            ->assertCreated()->json('data.id');
        $this->postJson('/api/v1/return-management/cases/'.$caseHash.'/actions', [
            'action' => 'agree',
            'resolution' => 'redelivery',
            'message' => 'Supplier will replace all verified missing goods.',
        ])->assertOk()->assertJsonPath('data.resolution', 'redelivery');

        return $caseHash;
    }

    /** @param array<int, array{0: PurchaseOrderItem, 1: string, 2: string}> $lines */
    private function receipt(PurchaseOrder $po, array $lines, ?Vendor $vendor = null, string $status = 'accepted', bool $beforeCase = false): GoodsReceiptNote
    {
        $grn = GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $po->id,
            'vendor_id' => ($vendor ?? $po->vendor)->id,
            'received_by' => $this->manager->id,
            'status' => $status,
        ]);
        foreach ($lines as [$poLine, $received, $accepted]) {
            GrnItem::create([
                'goods_receipt_note_id' => $grn->id,
                'purchase_order_item_id' => $poLine->id,
                'item_id' => $poLine->item_id,
                'location_id' => WarehouseLocation::factory()->create()->id,
                'quantity_received' => $received,
                'quantity_accepted' => $accepted,
                'unit_cost' => '10.0000',
            ]);
        }
        if ($beforeCase) {
            $grn->forceFill(['created_at' => now()->subMinute(), 'updated_at' => now()->subMinute()])->save();
        }

        return $grn;
    }

    private function caseData(string $caseHash): array
    {
        return $this->getJson('/api/v1/return-management/cases/'.$caseHash)
            ->assertOk()->json('data');
    }

    private function link(string $caseHash, array $receipts, bool $legacySingular = false)
    {
        $payload = ['action' => 'link_resolution'];
        if ($legacySingular) {
            $payload['resolution_goods_receipt_note_id'] = $receipts[0]->hash_id;
        } else {
            $payload['resolution_goods_receipt_note_ids'] = array_map(fn (GoodsReceiptNote $grn): string => $grn->hash_id, $receipts);
        }

        return $this->postJson('/api/v1/return-management/cases/'.$caseHash.'/actions', $payload);
    }

    public function test_ten_owed_units_settle_as_four_then_six_and_duplicate_add_is_idempotent(): void
    {
        [$po, $lines] = $this->order(['10.000']);
        $caseHash = $this->submitCase($po, [[$lines[0], '10.000']]);
        $first = $this->receipt($po, [[$lines[0], '6.000', '4.000']], status: 'partial_accepted');

        $firstLink = $this->link($caseHash, [$first], legacySingular: true)->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.lines.0.redelivered_quantity', '4.000')
            ->assertJsonPath('data.lines.0.remaining_redelivery_quantity', '6.000')
            ->assertJsonCount(1, 'data.resolution_receipts');
        $this->assertSame([$first->hash_id], array_column($firstLink->json('data.resolution_receipts'), 'id'));

        $this->link($caseHash, [$first], legacySingular: true)->assertOk()
            ->assertJsonPath('data.lines.0.redelivered_quantity', '4.000')
            ->assertJsonCount(1, 'data.resolution_receipts');
        $this->postJson('/api/v1/return-management/cases/'.$caseHash.'/actions', ['action' => 'resolve'])->assertUnprocessable();
        $this->assertSame('in_progress', $this->caseData($caseHash)['status']);

        $second = $this->receipt($po, [[$lines[0], '6.000', '6.000']], status: 'accepted');
        $finalLink = $this->link($caseHash, [$second])->assertOk()
            ->assertJsonPath('data.lines.0.redelivered_quantity', '10.000')
            ->assertJsonPath('data.lines.0.remaining_redelivery_quantity', '0.000')
            ->assertJsonCount(2, 'data.resolution_receipts');
        $this->assertSame([$first->hash_id, $second->hash_id], array_column($finalLink->json('data.resolution_receipts'), 'id'));
        $this->postJson('/api/v1/return-management/cases/'.$caseHash.'/actions', ['action' => 'resolve'])
            ->assertOk()->assertJsonPath('data.status', 'resolved');
    }

    public function test_one_grn_item_allocation_is_shared_across_cases_without_double_counting(): void
    {
        [$po, $lines] = $this->order(['10.000']);
        $firstCase = $this->submitCase($po, [[$lines[0], '4.000']]);
        $secondCase = $this->submitCase($po, [[$lines[0], '6.000']]);
        $receipt = $this->receipt($po, [[$lines[0], '7.000', '7.000']], status: 'accepted');

        $this->link($firstCase, [$receipt])->assertOk()
            ->assertJsonPath('data.lines.0.redelivered_quantity', '4.000')
            ->assertJsonPath('data.lines.0.remaining_redelivery_quantity', '0.000');
        $this->link($firstCase, [$receipt])->assertOk()
            ->assertJsonPath('data.lines.0.redelivered_quantity', '4.000');
        $this->link($secondCase, [$receipt])->assertOk()
            ->assertJsonPath('data.lines.0.redelivered_quantity', '3.000')
            ->assertJsonPath('data.lines.0.remaining_redelivery_quantity', '3.000');

        $this->postJson('/api/v1/return-management/cases/'.$firstCase.'/actions', ['action' => 'resolve'])
            ->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->postJson('/api/v1/return-management/cases/'.$secondCase.'/actions', ['action' => 'resolve'])
            ->assertUnprocessable();
    }

    public function test_same_item_lines_keep_separate_accepted_quantities_and_ignore_rejected_portions(): void
    {
        $item = Item::factory()->create(['unit_of_measure' => 'KG']);
        [$po, $lines] = $this->order(['3.000', '3.000'], item: $item);
        $caseHash = $this->submitCase($po, [[$lines[0], '3.000'], [$lines[1], '3.000']]);
        $grn = $this->receipt($po, [
            [$lines[0], '3.000', '2.000'],
            [$lines[1], '3.000', '2.000'],
        ], status: 'partial_accepted');

        $response = $this->link($caseHash, [$grn])->assertOk()
            ->assertJsonPath('data.lines.0.redelivered_quantity', '2.000')
            ->assertJsonPath('data.lines.0.remaining_redelivery_quantity', '1.000')
            ->assertJsonPath('data.lines.1.redelivered_quantity', '2.000')
            ->assertJsonPath('data.lines.1.remaining_redelivery_quantity', '1.000');
        $total = collect($response->json('data.lines'))->reduce(
            fn (string $sum, array $line): string => bcadd($sum, (string) $line['redelivered_quantity'], 3),
            '0.000',
        );
        $this->assertSame('4.000', $total);
    }

    public function test_wrong_party_po_or_pre_case_receipt_cannot_be_added(): void
    {
        [$po, $lines, $item, $vendor] = $this->order(['8.000']);
        $prior = $this->receipt($po, [[$lines[0], '4.000', '4.000']], status: 'accepted', beforeCase: true);
        $caseHash = $this->submitCase($po, [[$lines[0], '4.000']]);
        $wrongParty = $this->receipt($po, [[$lines[0], '4.000', '4.000']], vendor: Vendor::factory()->create());
        [$otherPo, $otherLines] = $this->order(['4.000'], vendor: $vendor, item: $item);
        $wrongPo = $this->receipt($otherPo, [[$otherLines[0], '4.000', '4.000']]);
        $pending = $this->receipt($po, [[$lines[0], '4.000', '0.000']], status: 'pending_qc');

        $options = $this->getJson('/api/v1/return-management/cases/'.$caseHash.'/resolution-options')->assertOk()->json('data');
        $listed = array_column($options['goods_receipts'], 'id');
        foreach ([$prior, $wrongParty, $wrongPo, $pending] as $grn) {
            $this->assertNotContains($grn->hash_id, $listed);
            $this->link($caseHash, [$grn])->assertUnprocessable();
        }
        $this->assertSame([], $this->caseData($caseHash)['resolution_receipts']);
    }

    public function test_supplier_and_warehouse_roles_cannot_link_but_approve_role_can(): void
    {
        [$po, $lines, , $vendor] = $this->order(['2.000']);
        $caseHash = $this->submitCase($po, [[$lines[0], '2.000']]);
        $grn = $this->receipt($po, [[$lines[0], '2.000', '2.000']]);
        $warehouse = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'warehouse_staff')->value('id'),
        ]);
        $this->assertFalse($warehouse->hasPermission('return_management.manage'));
        $this->actingAs($warehouse)->postJson('/api/v1/return-management/cases/'.$caseHash.'/actions', [
            'action' => 'link_resolution',
            'resolution_goods_receipt_note_ids' => [$grn->hash_id],
        ])->assertForbidden();

        $approver = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'department_head')->value('id'),
        ]);
        $this->assertTrue($approver->hasPermission('return_management.approve'));
        $this->assertFalse($approver->hasPermission('return_management.manage'));
        $this->actingAs($approver)->postJson('/api/v1/return-management/cases/'.$caseHash.'/actions', [
            'action' => 'link_resolution',
            'resolution_goods_receipt_note_ids' => [$grn->hash_id],
        ])->assertOk()->assertJsonPath('data.lines.0.redelivered_quantity', '2.000');

        $supplier = SupplierPortalUser::create([
            'vendor_id' => $vendor->id,
            'name' => 'Supplier Contact',
            'email' => 'split-receipt-supplier@test.local',
            'password' => bcrypt('Password1!'),
            'is_active' => true,
            'password_changed_at' => now(),
        ]);
        $this->actingAs($supplier, 'supplier_portal')->postJson('/api/v1/b2b/supplier/problems/'.$caseHash.'/actions', [
            'action' => 'link_resolution',
            'resolution_goods_receipt_note_ids' => [$grn->hash_id],
        ])->assertForbidden();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\PortalShippingDocument;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\B2B\Models\SupplierShipment;
use App\Modules\B2B\Models\SupplierShipmentItem;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Supplier shipments (several per PO), shipping documents, and the internal
 * read side that finally shows them to purchasing and incoming QC.
 */
class SupplierShipmentTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    private SupplierPortalUser $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, UomSeeder::class]);
        Storage::fake('local');

        $this->vendor = Vendor::factory()->create();
        $this->supplier = SupplierPortalUser::create([
            'vendor_id' => $this->vendor->id,
            'name' => 'Ship-'.substr(uniqid(), -5),
            'email' => 'ship-'.uniqid().'@t.test',
            'password' => bcrypt('Password1!'),
            'is_active' => true,
        ]);
    }

    private function makePo(string $status = 'acknowledged', string $ordered = '100.00', string $received = '0.00', ?Vendor $vendor = null): PurchaseOrder
    {
        $po = PurchaseOrder::factory()->create(['vendor_id' => ($vendor ?? $this->vendor)->id]);
        $po->forceFill(['status' => $status, 'sent_to_supplier_at' => now()])->save();
        $item = Item::factory()->create(['unit_of_measure' => 'kg']);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Wiper bushing resin',
            'quantity' => $ordered,
            'quantity_received' => $received,
            'quantity_accepted' => $received,
            'unit' => 'kg',
            'unit_price' => '10.00',
            'total' => Money::mul($ordered, '10.00'),
        ]);

        return $po->refresh();
    }

    private function asSupplier(): self
    {
        Sanctum::actingAs($this->supplier, ['*'], 'supplier_portal');

        return $this;
    }

    private function asRole(string $slug): User
    {
        $user = User::factory()->create(['role_id' => Role::query()->where('slug', $slug)->value('id')]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function shipmentsUrl(PurchaseOrder $po): string
    {
        return "/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/shipments";
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'shipped_date' => now()->subDay()->toDateString(),
            'carrier' => 'DHL Express',
            'tracking_number' => 'DHL123456',
            'estimated_arrival' => now()->addDays(3)->toDateString(),
            'notes' => 'Balance follows in a later shipment.',
            'items' => [[
                'purchase_order_item_id' => PurchaseOrderItem::query()->orderByDesc('id')->firstOrFail()->hash_id,
                'quantity_shipped' => '10.00',
            ]],
        ], $overrides);
    }

    private function postShipment(string $url, array $payload): TestResponse
    {
        static $photoNumber = 0;
        $photoNumber++;

        return $this->post($url, array_merge($payload, [
            'dispatch_photo' => UploadedFile::fake()->image('dispatch.jpg', 10 + $photoNumber, 10),
        ]), ['Accept' => 'application/json']);
    }

    public function test_new_dispatch_requires_quantified_po_lines_and_does_not_receive_goods(): void
    {
        $po = $this->makePo();
        $line = $po->items()->firstOrFail();
        $foreign = $this->makePo()->items()->firstOrFail();
        $this->asSupplier();
        $url = $this->shipmentsUrl($po);
        $this->postJson($url, $this->payload())->assertUnprocessable()->assertJsonValidationErrors('dispatch_photo');
        $this->post($url, array_merge($this->payload(), [
            'dispatch_photo' => UploadedFile::fake()->create('dispatch.pdf', 20, 'application/pdf'),
        ]), ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('dispatch_photo');
        $this->assertSame(0, SupplierShipment::query()->where('purchase_order_id', $po->id)->count());
        $this->postShipment($url, [])->assertUnprocessable();
        $this->postShipment($url, $this->payload(['items' => []]))->assertUnprocessable();
        $this->postShipment($url, $this->payload(['items' => [['purchase_order_item_id' => $foreign->hash_id, 'quantity_shipped' => '1.00']]]))->assertUnprocessable();
        $this->postShipment($url, $this->payload(['items' => [['purchase_order_item_id' => $line->hash_id, 'quantity_shipped' => '0']]]))->assertUnprocessable();
        $this->postShipment($url, $this->payload(['items' => [['purchase_order_item_id' => $line->hash_id, 'quantity_shipped' => '10000000000.00']]]))->assertUnprocessable();
        $this->assertSame(0, PortalShippingDocument::query()->where('purchase_order_id', $po->id)->count());
        $this->assertSame([], Storage::disk('local')->allFiles("portal/shipping-docs/{$po->id}"));
        $first = $this->postShipment($url, $this->payload(['items' => [['purchase_order_item_id' => $line->hash_id, 'quantity_shipped' => '12.00']]]))
            ->assertCreated()->assertJsonPath('data.quantity_recorded', true)
            ->assertJsonPath('data.items.0.purchase_order_item_id', $line->hash_id);
        $firstShipment = SupplierShipment::query()->where('purchase_order_id', $po->id)->firstOrFail();
        $photo = PortalShippingDocument::query()->where('supplier_shipment_id', $firstShipment->id)
            ->where('document_type', 'dispatch_photo')->firstOrFail();
        Storage::disk('local')->assertExists($photo->file_path);
        $this->postShipment($url, $this->payload(['items' => [['purchase_order_item_id' => $line->hash_id, 'quantity_shipped' => '8.00']]]))->assertCreated();
        $this->assertSame(2, PortalShippingDocument::query()->where('purchase_order_id', $po->id)->where('document_type', 'dispatch_photo')->count());
        $this->assertSame('0.00', $line->fresh()->quantity_received);
        $this->assertNotSame($first->json('data.id'), SupplierShipment::query()->latest('id')->firstOrFail()->hash_id);
    }

    public function test_two_shipments_on_one_po_both_persist(): void
    {
        $po = $this->makePo();
        $this->asSupplier();

        $first = $this->postShipment($this->shipmentsUrl($po), $this->payload(['tracking_number' => 'LOT-1']))->assertCreated();
        $second = $this->postShipment($this->shipmentsUrl($po), $this->payload(['tracking_number' => 'LOT-2']))->assertCreated();

        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(2, SupplierShipment::query()->where('purchase_order_id', $po->id)->count());

        $detail = $this->getJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}")->assertOk();
        $this->assertCount(2, $detail->json('data.shipments'));
        $this->assertSame('LOT-2', $detail->json('data.shipment.tracking_number'), 'shipment = latest');
        $drafts = GoodsReceiptNote::query()->where('purchase_order_id', $po->id)->where('status', 'draft')->get();
        $this->assertCount(2, $drafts);
        $this->assertEqualsCanonicalizing($po->supplierShipments()->pluck('id')->all(), $drafts->pluck('supplier_shipment_id')->all());
        $this->assertTrue($drafts->every(fn (GoodsReceiptNote $grn): bool => $grn->received_date === null));
        $this->assertSame('0.00', $po->items()->firstOrFail()->quantity_received);
        app(GrnService::class)->createDraftForSupplierShipment(SupplierShipment::findOrFail($drafts->first()->supplier_shipment_id));
        $this->assertSame(2, GoodsReceiptNote::query()->where('purchase_order_id', $po->id)->count());
        try {
            app(GrnService::class)->finalizeDraft(
                $drafts->first(),
                [],
                $this->asRole('warehouse_staff'),
                SupplierShipment::findOrFail($drafts->last()->supplier_shipment_id)->hash_id,
            );
            $this->fail('A staged GRN accepted a different supplier shipment.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('This GRN is linked to a different supplier shipment.', $e->getMessage());
        }
    }

    public function test_reusing_one_dispatch_photo_does_not_create_another_shipment(): void
    {
        $po = $this->makePo();
        $this->asSupplier();
        $url = $this->shipmentsUrl($po);
        $firstPhoto = UploadedFile::fake()->image('dispatch.jpg');
        $this->post($url, array_merge($this->payload(), ['dispatch_photo' => $firstPhoto]), ['Accept' => 'application/json'])
            ->assertCreated();

        $samePhoto = UploadedFile::fake()->image('dispatch.jpg');
        $this->post($url, array_merge($this->payload(), ['dispatch_photo' => $samePhoto]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This dispatch photo is already attached to the purchase order. Take a new photo for this shipment.');
        $this->assertSame(1, SupplierShipment::query()->where('purchase_order_id', $po->id)->count());
        $this->assertSame(1, PortalShippingDocument::query()->where('purchase_order_id', $po->id)->count());
    }

    public function test_dispatch_cannot_exceed_ordered_quantity_across_partial_shipments(): void
    {
        $po = $this->makePo(ordered: '20000.00');
        $line = $po->items()->firstOrFail();
        $url = $this->shipmentsUrl($po);
        $this->asSupplier();

        $this->postShipment($url, $this->payload(['items' => [[
            'purchase_order_item_id' => $line->hash_id, 'quantity_shipped' => '20000.01',
        ]]]))->assertUnprocessable();
        $this->postShipment($url, $this->payload(['notes' => '', 'items' => [[
            'purchase_order_item_id' => $line->hash_id, 'quantity_shipped' => '12000.00',
        ]]]))->assertUnprocessable()->assertJsonPath('message', 'Reason is required when a shipment leaves PO quantity undispatched.');
        $this->postShipment($url, $this->payload(['items' => [[
            'purchase_order_item_id' => $line->hash_id, 'quantity_shipped' => '12000.00',
        ]]]))->assertCreated();
        $this->postShipment($url, $this->payload(['items' => [[
            'purchase_order_item_id' => $line->hash_id, 'quantity_shipped' => '8000.01',
        ]]]))->assertUnprocessable();
        $this->postShipment($url, $this->payload(['notes' => '', 'items' => [[
            'purchase_order_item_id' => $line->hash_id, 'quantity_shipped' => '8000.00',
        ]]]))->assertCreated();
        $this->postShipment($url, $this->payload(['items' => [[
            'purchase_order_item_id' => $line->hash_id, 'quantity_shipped' => '0.01',
        ]]]))->assertUnprocessable();

        $this->assertSame(2, $po->supplierShipments()->count());
        $this->assertSame('20000.00', (string) SupplierShipmentItem::query()
            ->where('purchase_order_item_id', $line->id)->sum('quantity_shipped'));
    }

    public function test_update_changes_only_the_addressed_shipment_and_keeps_history(): void
    {
        $po = $this->makePo();
        $this->asSupplier();
        $a = $this->postShipment($this->shipmentsUrl($po), $this->payload(['tracking_number' => 'A']))->json('data.id');
        $b = $this->postShipment($this->shipmentsUrl($po), $this->payload(['tracking_number' => 'B']))->json('data.id');

        $this->putJson($this->shipmentsUrl($po)."/{$a}", ['carrier' => 'FedEx'])
            ->assertOk()
            ->assertJsonPath('data.carrier', 'FedEx')
            ->assertJsonPath('data.tracking_number', 'A');

        $this->assertSame('DHL Express', SupplierShipment::query()->where('tracking_number', 'B')->value('carrier'));
        $shipmentA = SupplierShipment::query()->where('tracking_number', 'A')->firstOrFail();
        $this->assertSame(2, $shipmentA->updates()->count());
        $this->assertNotSame($a, $b);
    }

    public function test_received_shipment_is_locked_while_unreceived_replacement_remains_editable(): void
    {
        $po = $this->makePo('partially_received', '100.00', '90.00');
        $line = $po->items()->firstOrFail();
        $this->asSupplier();
        $firstId = $this->postShipment($this->shipmentsUrl($po), $this->payload([
            'items' => [['purchase_order_item_id' => $line->hash_id, 'quantity_shipped' => '90.00']],
        ]))->assertCreated()->json('data.id');
        $secondId = $this->postShipment($this->shipmentsUrl($po), $this->payload([
            'items' => [['purchase_order_item_id' => $line->hash_id, 'quantity_shipped' => '10.00']],
        ]))->assertCreated()->json('data.id');

        $first = SupplierShipment::findOrFail(HashIdFilter::decode($firstId, SupplierShipment::class));
        $first->goodsReceiptNotes()->firstOrFail()->forceFill([
            'received_date' => now()->toDateString(),
            'status' => 'pending_qc',
        ])->save();
        $this->assertSame($firstId, $first->hash_id);
        $this->assertTrue($first->goodsReceiptNotes()->whereNotNull('received_date')->exists());

        $detail = $this->getJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}")->assertOk();
        $shipments = collect($detail->json('data.shipments'))->keyBy('id');
        $this->assertFalse($shipments[$firstId]['can_edit']);
        $this->assertTrue($shipments[$secondId]['can_edit']);

        $this->putJson($this->shipmentsUrl($po)."/{$firstId}", ['carrier' => 'Changed'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This shipment has already been received and cannot be edited.');
        $this->putJson($this->shipmentsUrl($po)."/{$secondId}", ['carrier' => 'Changed'])
            ->assertOk()->assertJsonPath('data.carrier', 'Changed');
        $this->assertSame('DHL Express', $first->fresh()->carrier);
    }

    public function test_a_passed_eta_does_not_block_correcting_other_fields(): void
    {
        $po = $this->makePo();
        $shipment = SupplierShipment::factory()->create([
            'purchase_order_id' => $po->id,
            'shipped_date' => now()->subDays(10)->toDateString(),
            'estimated_arrival' => now()->subDays(2)->toDateString(),
        ]);

        $this->asSupplier()->putJson($this->shipmentsUrl($po)."/{$shipment->hash_id}", ['tracking_number' => 'FIXED'])
            ->assertOk()
            ->assertJsonPath('data.quantity_recorded', false)
            ->assertJsonPath('data.late_eta', false);
    }

    public function test_legacy_unquantified_shipment_must_be_reconciled_before_another_dispatch(): void
    {
        $po = $this->makePo(ordered: '100.00');
        $line = $po->items()->firstOrFail();
        $legacy = SupplierShipment::factory()->create([
            'purchase_order_id' => $po->id,
            'shipped_date' => now()->subDay()->toDateString(),
            'estimated_arrival' => now()->addDay()->toDateString(),
            'notes' => null,
        ]);
        $this->asSupplier();

        $this->postShipment($this->shipmentsUrl($po), $this->payload())->assertUnprocessable();
        $this->putJson($this->shipmentsUrl($po)."/{$legacy->hash_id}", [
            'items' => [['purchase_order_item_id' => $line->hash_id, 'quantity_shipped' => '40.00']],
        ])->assertUnprocessable();
        $this->putJson($this->shipmentsUrl($po)."/{$legacy->hash_id}", [
            'notes' => 'Balance follows in a later shipment.',
            'items' => [['purchase_order_item_id' => $line->hash_id, 'quantity_shipped' => '40.00']],
        ])->assertOk()->assertJsonPath('data.quantity_recorded', true);
        $this->postShipment($this->shipmentsUrl($po), $this->payload([
            'items' => [['purchase_order_item_id' => $line->hash_id, 'quantity_shipped' => '61.00']],
        ]))->assertUnprocessable();
        $this->postShipment($this->shipmentsUrl($po), $this->payload([
            'items' => [['purchase_order_item_id' => $line->hash_id, 'quantity_shipped' => '60.00']],
        ]))->assertCreated();
        $this->putJson($this->shipmentsUrl($po)."/{$legacy->hash_id}", [
            'items' => [['purchase_order_item_id' => $line->hash_id, 'quantity_shipped' => '1.00']],
        ])->assertUnprocessable();
    }

    public function test_date_rules(): void
    {
        $po = $this->makePo();
        $this->asSupplier();

        $this->postShipment($this->shipmentsUrl($po), $this->payload(['estimated_arrival' => now()->subDay()->toDateString()]))
            ->assertUnprocessable()->assertJsonValidationErrors('estimated_arrival');
        $this->postShipment($this->shipmentsUrl($po), $this->payload(['shipped_date' => now()->addDay()->toDateString()]))
            ->assertUnprocessable()->assertJsonValidationErrors('shipped_date');

        // ETA before the stored ship date, supplied on its own in an update.
        $shipment = SupplierShipment::factory()->create([
            'purchase_order_id' => $po->id,
            'shipped_date' => now()->toDateString(),
            'estimated_arrival' => now()->addDays(5)->toDateString(),
        ]);
        $this->travel(-1)->days();
        $this->putJson($this->shipmentsUrl($po)."/{$shipment->hash_id}", ['estimated_arrival' => now()->toDateString()])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Estimated arrival must be on or after the shipped date.');
    }

    public function test_eta_does_not_move_the_agreed_delivery_date(): void
    {
        $po = $this->makePo();
        $po->forceFill(['confirmed_delivery_date' => now()->addDays(10)->toDateString()])->save();

        $this->asSupplier()->postShipment($this->shipmentsUrl($po), $this->payload(['estimated_arrival' => now()->addDays(20)->toDateString()]))
            ->assertCreated();

        $this->assertSame(now()->addDays(10)->toDateString(), $po->fresh()->confirmed_delivery_date->toDateString());
    }

    public function test_late_eta_flags_and_notifies_purchasing_once_per_date(): void
    {
        $po = $this->makePo();
        $deadline = now()->addDays(2)->toDateString();
        $po->forceFill(['confirmed_delivery_date' => $deadline])->save();
        $buyer = User::factory()->create(['role_id' => Role::query()->where('slug', 'purchasing_officer')->value('id')]);
        $this->asSupplier();
        $late = $this->postShipment($this->shipmentsUrl($po), $this->payload(['estimated_arrival' => now()->addDays(3)->toDateString()]))
            ->assertCreated()->assertJsonPath('data.late_eta', true);
        $id = $late->json('data.id');
        $this->putJson($this->shipmentsUrl($po)."/{$id}", ['estimated_arrival' => now()->addDays(3)->toDateString()])->assertOk();
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $buyer->id)->where('type', 'supplier.shipment_late')->count());
        $notice = DB::table('notifications')->where('notifiable_id', $buyer->id)->where('type', 'supplier.shipment_late')->firstOrFail();
        $noticeData = json_decode($notice->data, true);
        $this->assertSame('/purchasing/purchase-orders/'.$po->hash_id, $noticeData['link_to']);
        $this->assertSame('purchasing_delivery_schedule_reviewer', $noticeData['recipient_reason']);
        $this->putJson($this->shipmentsUrl($po)."/{$id}", ['estimated_arrival' => now()->addDay()->toDateString()])
            ->assertOk()->assertJsonPath('data.late_eta', false);
        $this->assertSame($deadline, $po->fresh()->confirmed_delivery_date?->toDateString());
    }

    public function test_late_eta_notice_excludes_system_admin_and_users_who_cannot_open_the_po(): void
    {
        $po = $this->makePo();
        $po->forceFill(['confirmed_delivery_date' => now()->addDays(2)->toDateString()])->save();
        $systemAdmin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        $reviewerRole = Role::create([
            'name' => 'Schedule reviewer '.substr(uniqid(), -5),
            'slug' => 'schedule_reviewer_'.substr(uniqid(), -5),
            'is_system' => false,
        ]);
        $reviewerRole->permissions()->sync([
            Permission::query()->where('slug', 'purchasing.delivery_schedules.review')->value('id'),
        ]);
        $restrictedReviewer = User::factory()->create(['role_id' => $reviewerRole->id]);

        $this->asSupplier()->postShipment(
            $this->shipmentsUrl($po),
            $this->payload(['estimated_arrival' => now()->addDays(3)->toDateString()]),
        )->assertCreated();

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $systemAdmin->id,
            'type' => 'supplier.shipment_late',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $restrictedReviewer->id,
            'type' => 'supplier.shipment_late',
        ]);

        $this->actingAs($restrictedReviewer, 'sanctum')
            ->getJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}")
            ->assertForbidden();
    }

    public function test_shipments_require_an_accepted_po_with_open_quantity(): void
    {
        $this->asSupplier();

        $this->postShipment($this->shipmentsUrl($this->makePo('sent')), $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Shipment updates open once you accept the purchase order and close when every line has been received.');
        $this->postShipment($this->shipmentsUrl($this->makePo('partially_received', '100.00', '100.00')), $this->payload())
            ->assertUnprocessable();
        $this->postShipment($this->shipmentsUrl($this->makePo('partially_received', '100.00', '40.00')), $this->payload())
            ->assertCreated();
    }

    public function test_documents_follow_acceptance_not_open_quantity_and_accept_a_coa(): void
    {
        $this->asSupplier();
        $upload = fn (PurchaseOrder $po, string $type = 'certificate_of_analysis') => $this->post(
            "/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/shipping-documents",
            ['document_type' => $type, 'file' => UploadedFile::fake()->create('coa.pdf', 20, 'application/pdf')],
            ['Accept' => 'application/json'],
        );

        $upload($this->makePo('sent'))->assertUnprocessable();
        // Everything received, but QC may still ask for the certificate.
        $upload($this->makePo('partially_received', '100.00', '100.00'))->assertCreated();
        $this->assertDatabaseHas('portal_shipping_documents', ['document_type' => 'certificate_of_analysis']);
    }

    public function test_shipping_documents_follow_the_linked_dispatch_or_the_whole_po(): void
    {
        $po = $this->makePo();
        $a = SupplierShipment::factory()->create(['purchase_order_id' => $po->id]);
        $b = SupplierShipment::factory()->create(['purchase_order_id' => $po->id]);
        $foreign = SupplierShipment::factory()->create(['purchase_order_id' => $this->makePo()->id]);
        $this->asSupplier();
        $url = "/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/shipping-documents";
        $this->post($url, [
            'document_type' => 'packing_list', 'supplier_shipment_id' => $a->hash_id,
            'file' => UploadedFile::fake()->create('lot-a.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.supplier_shipment_id', $a->hash_id);
        $this->post($url, [
            'document_type' => 'packing_list', 'supplier_shipment_id' => $foreign->hash_id,
            'file' => UploadedFile::fake()->create('foreign.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        PortalShippingDocument::factory()->create(['purchase_order_id' => $po->id, 'supplier_shipment_id' => $b->id, 'document_type' => 'bill_of_lading']);
        PortalShippingDocument::factory()->create(['purchase_order_id' => $po->id, 'document_type' => 'certificate_of_analysis']);
        $grn = GoodsReceiptNote::factory()->create(['purchase_order_id' => $po->id, 'vendor_id' => $po->vendor_id, 'supplier_shipment_id' => $a->id]);
        $this->asRole('warehouse_staff');
        $response = $this->getJson("/api/v1/b2b/goods-receipt-notes/{$grn->hash_id}/supplier-documents")->assertOk();
        $this->assertCount(2, $response->json('data.documents'));
        $this->assertEqualsCanonicalizing(['packing_list', 'certificate_of_analysis'], $response->json('data.documents.*.document_type'));
    }

    public function test_dispatch_photo_requires_an_image_linked_to_a_shipment(): void
    {
        $po = $this->makePo();
        $shipment = SupplierShipment::factory()->create(['purchase_order_id' => $po->id]);
        $this->asSupplier();
        $url = "/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/shipping-documents";

        $this->post($url, [
            'document_type' => 'dispatch_photo',
            'file' => UploadedFile::fake()->image('dispatch.jpg'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('supplier_shipment_id');
        $this->post($url, [
            'document_type' => 'dispatch_photo', 'supplier_shipment_id' => $shipment->hash_id,
            'file' => UploadedFile::fake()->create('dispatch.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post($url, [
            'document_type' => 'dispatch_photo', 'supplier_shipment_id' => $shipment->hash_id,
            'file' => UploadedFile::fake()->image('dispatch.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.document_type_label', 'Dispatch photo')
            ->assertJsonPath('data.supplier_shipment_id', $shipment->hash_id);
    }

    public function test_another_vendors_shipment_is_not_found(): void
    {
        $mine = $this->makePo();
        $theirs = $this->makePo('acknowledged', vendor: Vendor::factory()->create());
        $foreign = SupplierShipment::factory()->create(['purchase_order_id' => $theirs->id]);

        $this->asSupplier()
            ->putJson($this->shipmentsUrl($mine)."/{$foreign->hash_id}", ['carrier' => 'X'])
            ->assertNotFound();
    }

    public function test_internal_activity_is_permission_and_row_scoped(): void
    {
        $po = $this->makePo();
        SupplierShipment::factory()->create(['purchase_order_id' => $po->id]);
        PortalShippingDocument::factory()->create(['purchase_order_id' => $po->id, 'document_type' => 'packing_list']);
        $url = "/api/v1/b2b/purchase-orders/{$po->hash_id}/supplier-activity";

        $this->asRole('purchasing_officer');
        $this->getJson($url)
            ->assertOk()
            ->assertJsonCount(1, 'data.shipments')
            ->assertJsonCount(1, 'data.documents')
            ->assertJsonStructure(['data' => ['shipments', 'documents', 'delivery_schedules']]);

        $this->asRole('driver');
        $this->getJson($url)->assertForbidden();
    }

    public function test_grn_supplier_documents_put_the_coa_first(): void
    {
        $po = $this->makePo();
        $grn = GoodsReceiptNote::factory()->create(['purchase_order_id' => $po->id, 'vendor_id' => $this->vendor->id]);
        $shipment = SupplierShipment::factory()->create(['purchase_order_id' => $po->id]);
        $return = ReturnRequest::create([
            'rma_number' => 'RMA-TEST-SHIPMENT',
            'type' => 'supplier_return',
            'status' => 'completed',
            'purchase_order_id' => $po->id,
            'vendor_id' => $this->vendor->id,
        ]);
        $shipment->forceFill(['return_request_id' => $return->id])->save();
        $grn->forceFill(['supplier_shipment_id' => $shipment->id])->save();
        PortalShippingDocument::factory()->create(['purchase_order_id' => $po->id, 'document_type' => 'packing_list', 'uploaded_at' => now()]);
        PortalShippingDocument::factory()->create(['purchase_order_id' => $po->id, 'document_type' => 'certificate_of_analysis', 'uploaded_at' => now()->subDay()]);

        $this->asRole('qc_inspector');
        $response = $this->getJson("/api/v1/b2b/goods-receipt-notes/{$grn->hash_id}/supplier-documents")->assertOk();

        $this->assertNotNull($response->json('data.shipment'));
        $this->assertSame('RMA-TEST-SHIPMENT', $response->json('data.shipment.return_rma_number'));
        $this->assertSame('certificate_of_analysis', $response->json('data.documents.0.document_type'));
    }

    public function test_internal_document_download_requires_visibility(): void
    {
        $po = $this->makePo();
        Storage::disk('local')->put('portal/shipping-docs/coa.pdf', 'pdf');
        $doc = PortalShippingDocument::factory()->create([
            'purchase_order_id' => $po->id,
            'file_path' => 'portal/shipping-docs/coa.pdf',
        ]);
        $url = "/api/v1/b2b/supplier-documents/{$doc->hash_id}/download";

        $this->asRole('driver');
        $this->get($url)->assertNotFound();

        $this->asRole('purchasing_officer');
        $this->get($url)->assertOk();
    }
}

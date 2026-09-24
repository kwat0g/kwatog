<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Common\Support\Money;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\PortalShippingDocument;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\B2B\Models\SupplierShipment;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        Storage::fake('local');

        $this->vendor = Vendor::factory()->create();
        $this->supplier = SupplierPortalUser::create([
            'vendor_id' => $this->vendor->id,
            'name'      => 'Ship-'.substr(uniqid(), -5),
            'email'     => 'ship-'.uniqid().'@t.test',
            'password'  => bcrypt('Password1!'),
            'is_active' => true,
        ]);
    }

    private function makePo(string $status = 'acknowledged', string $ordered = '100.00', string $received = '0.00', ?Vendor $vendor = null): PurchaseOrder
    {
        $po = PurchaseOrder::factory()->create(['vendor_id' => ($vendor ?? $this->vendor)->id]);
        $po->forceFill(['status' => $status, 'sent_to_supplier_at' => now()])->save();
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => Item::factory()->create()->id,
            'description'       => 'Wiper bushing resin',
            'quantity'          => $ordered,
            'quantity_received' => $received,
            'quantity_accepted' => $received,
            'unit'              => 'kg',
            'unit_price'        => '10.00',
            'total'             => Money::mul($ordered, '10.00'),
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
        $this->actingAs($user);

        return $user;
    }

    private function shipmentsUrl(PurchaseOrder $po): string
    {
        return "/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/shipments";
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'shipped_date'      => now()->subDay()->toDateString(),
            'carrier'           => 'DHL Express',
            'tracking_number'   => 'DHL123456',
            'estimated_arrival' => now()->addDays(3)->toDateString(),
        ], $overrides);
    }

    public function test_two_shipments_on_one_po_both_persist(): void
    {
        $po = $this->makePo();
        $this->asSupplier();

        $first = $this->postJson($this->shipmentsUrl($po), $this->payload(['tracking_number' => 'LOT-1']))->assertCreated();
        $second = $this->postJson($this->shipmentsUrl($po), $this->payload(['tracking_number' => 'LOT-2']))->assertCreated();

        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(2, SupplierShipment::query()->where('purchase_order_id', $po->id)->count());

        $detail = $this->getJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}")->assertOk();
        $this->assertCount(2, $detail->json('data.shipments'));
        $this->assertSame('LOT-2', $detail->json('data.shipment.tracking_number'), 'shipment = latest');
    }

    public function test_update_changes_only_the_addressed_shipment_and_keeps_history(): void
    {
        $po = $this->makePo();
        $this->asSupplier();
        $a = $this->postJson($this->shipmentsUrl($po), $this->payload(['tracking_number' => 'A']))->json('data.id');
        $b = $this->postJson($this->shipmentsUrl($po), $this->payload(['tracking_number' => 'B']))->json('data.id');

        $this->putJson($this->shipmentsUrl($po)."/{$a}", ['carrier' => 'FedEx'])
            ->assertOk()
            ->assertJsonPath('data.carrier', 'FedEx')
            ->assertJsonPath('data.tracking_number', 'A');

        $this->assertSame('DHL Express', SupplierShipment::query()->where('tracking_number', 'B')->value('carrier'));
        $shipmentA = SupplierShipment::query()->where('tracking_number', 'A')->firstOrFail();
        $this->assertSame(2, $shipmentA->updates()->count());
        $this->assertNotSame($a, $b);
    }

    public function test_a_passed_eta_does_not_block_correcting_other_fields(): void
    {
        $po = $this->makePo();
        $shipment = SupplierShipment::factory()->create([
            'purchase_order_id' => $po->id,
            'shipped_date'      => now()->subDays(10)->toDateString(),
            'estimated_arrival' => now()->subDays(2)->toDateString(),
        ]);

        $this->asSupplier()->putJson($this->shipmentsUrl($po)."/{$shipment->hash_id}", ['tracking_number' => 'FIXED'])
            ->assertOk();
    }

    public function test_date_rules(): void
    {
        $po = $this->makePo();
        $this->asSupplier();

        $this->postJson($this->shipmentsUrl($po), $this->payload(['estimated_arrival' => now()->subDay()->toDateString()]))
            ->assertUnprocessable()->assertJsonValidationErrors('estimated_arrival');
        $this->postJson($this->shipmentsUrl($po), $this->payload(['shipped_date' => now()->addDay()->toDateString()]))
            ->assertUnprocessable()->assertJsonValidationErrors('shipped_date');

        // ETA before the stored ship date, supplied on its own in an update.
        $shipment = SupplierShipment::factory()->create([
            'purchase_order_id' => $po->id,
            'shipped_date'      => now()->toDateString(),
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

        $this->asSupplier()->postJson($this->shipmentsUrl($po), $this->payload(['estimated_arrival' => now()->addDays(20)->toDateString()]))
            ->assertCreated();

        $this->assertSame(now()->addDays(10)->toDateString(), $po->fresh()->confirmed_delivery_date->toDateString());
    }

    public function test_shipments_require_an_accepted_po_with_open_quantity(): void
    {
        $this->asSupplier();

        $this->postJson($this->shipmentsUrl($this->makePo('sent')), $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Shipment updates open once you accept the purchase order and close when every line has been received.');
        $this->postJson($this->shipmentsUrl($this->makePo('partially_received', '100.00', '100.00')), $this->payload())
            ->assertUnprocessable();
        $this->postJson($this->shipmentsUrl($this->makePo('partially_received', '100.00', '40.00')), $this->payload())
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
        SupplierShipment::factory()->create(['purchase_order_id' => $po->id]);
        PortalShippingDocument::factory()->create(['purchase_order_id' => $po->id, 'document_type' => 'packing_list', 'uploaded_at' => now()]);
        PortalShippingDocument::factory()->create(['purchase_order_id' => $po->id, 'document_type' => 'certificate_of_analysis', 'uploaded_at' => now()->subDay()]);

        $this->asRole('qc_inspector');
        $response = $this->getJson("/api/v1/b2b/goods-receipt-notes/{$grn->hash_id}/supplier-documents")->assertOk();

        $this->assertNotNull($response->json('data.shipment'));
        $this->assertSame('certificate_of_analysis', $response->json('data.documents.0.document_type'));
    }

    public function test_internal_document_download_requires_visibility(): void
    {
        $po = $this->makePo();
        Storage::disk('local')->put('portal/shipping-docs/coa.pdf', 'pdf');
        $doc = PortalShippingDocument::factory()->create([
            'purchase_order_id' => $po->id,
            'file_path'         => 'portal/shipping-docs/coa.pdf',
        ]);
        $url = "/api/v1/b2b/supplier-documents/{$doc->hash_id}/download";

        $this->asRole('driver');
        $this->get($url)->assertNotFound();

        $this->asRole('purchasing_officer');
        $this->get($url)->assertOk();
    }
}

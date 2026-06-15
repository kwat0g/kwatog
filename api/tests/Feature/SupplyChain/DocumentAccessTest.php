<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryProof;
use App\Modules\SupplyChain\Models\Shipment;
use App\Modules\SupplyChain\Models\ShipmentDocument;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * P0.4 — Document Access Security Tests.
 *
 * Verifies that all three SupplyChain upload handlers write to the LOCAL disk
 * (not public) and that their streaming download routes require authentication.
 */
class DocumentAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
        Storage::fake('public');
    }

    // ─── Delivery Proof tests ─────────────────────────────────────────────────

    public function test_delivery_proof_upload_writes_to_local_disk_not_public(): void
    {
        $user     = $this->seedUserWithPerms(['supply_chain.view', 'supply_chain.deliveries.create']);
        $delivery = $this->seedDelivery($user);

        $this->actingAs($user)
            ->postJson("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/proofs", [
                'proof_type' => 'photo',
                'file'       => UploadedFile::fake()->image('photo.jpg'),
            ])
            ->assertStatus(201);

        // File must exist on local disk.
        $proof = DeliveryProof::query()->where('delivery_id', $delivery->id)->firstOrFail();
        Storage::disk('local')->assertExists($proof->file_path);

        // File must NOT exist on the public disk.
        Storage::disk('public')->assertDirectoryEmpty('/');
    }

    public function test_delivery_proof_view_requires_authentication(): void
    {
        $user     = $this->seedUserWithPerms(['supply_chain.view', 'supply_chain.deliveries.create']);
        $delivery = $this->seedDelivery($user);

        // Create a fake proof record with a file on the local disk.
        $path = 'deliveries/' . $delivery->id . '/proofs/test.jpg';
        Storage::disk('local')->put($path, 'fake-image-bytes');
        $proof = DeliveryProof::create([
            'delivery_id' => $delivery->id,
            'proof_type'  => 'photo',
            'file_name'   => 'test.jpg',
            'file_path'   => $path,
            'mime_type'   => 'image/jpeg',
            'uploaded_by' => $user->id,
        ]);

        // Unauthenticated request must return 401.
        $this->getJson("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/proofs/{$proof->hash_id}/view")
            ->assertStatus(401);
    }

    public function test_delivery_proof_view_returns_200_for_authorized_user(): void
    {
        $user     = $this->seedUserWithPerms(['supply_chain.view', 'supply_chain.deliveries.create']);
        $delivery = $this->seedDelivery($user);

        $path = 'deliveries/' . $delivery->id . '/proofs/test.jpg';
        Storage::disk('local')->put($path, 'fake-image-bytes');
        $proof = DeliveryProof::create([
            'delivery_id' => $delivery->id,
            'proof_type'  => 'photo',
            'file_name'   => 'test.jpg',
            'file_path'   => $path,
            'mime_type'   => 'image/jpeg',
            'uploaded_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/proofs/{$proof->hash_id}/view")
            ->assertOk();
    }

    // ─── Shipment Document tests ──────────────────────────────────────────────

    public function test_shipment_document_upload_writes_to_local_disk_not_public(): void
    {
        $user     = $this->seedUserWithPerms(['supply_chain.view', 'supply_chain.shipments.manage']);
        $shipment = $this->seedShipment($user);

        $this->actingAs($user)
            ->postJson("/api/v1/supply-chain/shipments/{$shipment->hash_id}/documents", [
                'document_type' => 'commercial_invoice',
                'file'          => UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf'),
            ])
            ->assertSuccessful();

        $doc = ShipmentDocument::query()
            ->where('shipment_id', $shipment->id)->firstOrFail();

        Storage::disk('local')->assertExists($doc->file_path);
        Storage::disk('public')->assertDirectoryEmpty('/');
    }

    public function test_shipment_document_download_requires_authentication(): void
    {
        $user     = $this->seedUserWithPerms(['supply_chain.view', 'supply_chain.shipments.manage']);
        $shipment = $this->seedShipment($user);

        $path = 'shipments/' . $shipment->id . '/doc.pdf';
        Storage::disk('local')->put($path, 'fake-pdf-bytes');
        $doc = ShipmentDocument::create([
            'shipment_id'       => $shipment->id,
            'document_type'     => 'commercial_invoice',
            'file_path'         => $path,
            'original_filename' => 'doc.pdf',
            'mime_type'         => 'application/pdf',
            'uploaded_by'       => $user->id,
            'uploaded_at'       => now(),
        ]);

        $this->getJson("/api/v1/supply-chain/shipment-documents/{$doc->hash_id}/download")
            ->assertStatus(401);
    }

    public function test_shipment_document_download_returns_200_for_authorized_user(): void
    {
        $user     = $this->seedUserWithPerms(['supply_chain.view', 'supply_chain.shipments.manage']);
        $shipment = $this->seedShipment($user);

        $path = 'shipments/' . $shipment->id . '/doc.pdf';
        Storage::disk('local')->put($path, 'fake-pdf-bytes');
        $doc = ShipmentDocument::create([
            'shipment_id'       => $shipment->id,
            'document_type'     => 'commercial_invoice',
            'file_path'         => $path,
            'original_filename' => 'doc.pdf',
            'mime_type'         => 'application/pdf',
            'uploaded_by'       => $user->id,
            'uploaded_at'       => now(),
        ]);

        $this->actingAs($user)
            ->get("/api/v1/supply-chain/shipment-documents/{$doc->hash_id}/download")
            ->assertOk();
    }

    // ─── Delivery Receipt Photo tests ─────────────────────────────────────────

    public function test_receipt_photo_upload_writes_to_local_disk_not_public(): void
    {
        $user     = $this->seedUserWithPerms(['supply_chain.view', 'supply_chain.deliveries.create']);
        $delivery = $this->seedDelivery($user, status: 'delivered');

        $this->actingAs($user)
            ->post("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/receipt", [
                'file' => UploadedFile::fake()->image('receipt.jpg'),
            ])
            ->assertOk();

        $fresh = $delivery->fresh();
        $this->assertNotNull($fresh->receipt_photo_path);
        Storage::disk('local')->assertExists($fresh->receipt_photo_path);
        Storage::disk('public')->assertDirectoryEmpty('/');
    }

    public function test_receipt_photo_streaming_requires_authentication(): void
    {
        $user     = $this->seedUserWithPerms(['supply_chain.view', 'supply_chain.deliveries.create']);
        $delivery = $this->seedDelivery($user);

        $path = 'deliveries/' . $delivery->id . '/receipt.jpg';
        Storage::disk('local')->put($path, 'fake-photo-bytes');
        $delivery->forceFill(['receipt_photo_path' => $path])->save();

        $this->getJson("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/receipt-photo")
            ->assertStatus(401);
    }

    public function test_receipt_photo_streaming_returns_200_for_authorized_user(): void
    {
        $user     = $this->seedUserWithPerms(['supply_chain.view', 'supply_chain.deliveries.create']);
        $delivery = $this->seedDelivery($user);

        $path = 'deliveries/' . $delivery->id . '/receipt.jpg';
        Storage::disk('local')->put($path, 'fake-photo-bytes');
        $delivery->forceFill(['receipt_photo_path' => $path])->save();

        $this->actingAs($user)
            ->get("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/receipt-photo")
            ->assertOk();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function seedUserWithPerms(array $permSlugs): User
    {
        $role = Role::create([
            'name'        => 'Test SC Role ' . uniqid(),
            'slug'        => 'test_sc_' . uniqid(),
            'description' => 'Test',
        ]);
        foreach ($permSlugs as $slug) {
            $perm = Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'module' => explode('.', $slug)[0]],
            );
            $role->permissions()->syncWithoutDetaching([$perm->id]);
        }
        return User::factory()->create([
            'role_id' => $role->id,
            'email'   => 'sc_' . uniqid() . '@test.test',
        ]);
    }

    private function seedCustomer(): Customer
    {
        return Customer::create([
            'name'      => 'Test Customer ' . uniqid(),
            'is_active' => true,
        ]);
    }

    private function seedSalesOrder(User $user): SalesOrder
    {
        $customer = $this->seedCustomer();
        $so = SalesOrder::create([
            'so_number'    => 'SO-T-' . substr(uniqid(), -5),
            'customer_id'  => $customer->id,
            'date'         => now()->toDateString(),
            'subtotal'     => '10000.00',
            'vat_amount'   => '1200.00',
            'total_amount' => '11200.00',
            'created_by'   => $user->id,
        ]);
        $so->forceFill(['status' => 'confirmed'])->save();
        return $so;
    }

    private function seedDelivery(User $user, string $status = 'scheduled'): Delivery
    {
        $so = $this->seedSalesOrder($user);
        $delivery = Delivery::create([
            'delivery_number' => 'DEL-T-' . substr(uniqid(), -5),
            'sales_order_id'  => $so->id,
            'scheduled_date'  => now()->toDateString(),
            'created_by'      => $user->id,
        ]);
        $delivery->forceFill(['status' => $status])->save();
        return $delivery;
    }

    private function seedShipment(User $user): Shipment
    {
        // Shipment requires a PO; bootstrap the minimal chain.
        $vendor = Vendor::create([
            'name'      => 'Test Vendor ' . uniqid(),
            'is_active' => true,
        ]);
        $po = PurchaseOrder::create([
            'po_number'    => 'PO-T-' . substr(uniqid(), -5),
            'vendor_id'    => $vendor->id,
            'date'         => now()->toDateString(),
            'subtotal'     => '5000.00',
            'vat_amount'   => '600.00',
            'total_amount' => '5600.00',
            'created_by'   => $user->id,
        ]);
        $po->forceFill(['status' => 'approved'])->save();
        $shipment = Shipment::create([
            'shipment_number'   => 'SHP-T-' . substr(uniqid(), -5),
            'purchase_order_id' => $po->id,
            'created_by'        => $user->id,
        ]);
        $shipment->forceFill(['status' => 'ordered'])->save();
        return $shipment;
    }
}

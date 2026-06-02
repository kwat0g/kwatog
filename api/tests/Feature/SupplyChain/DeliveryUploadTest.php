<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Services\DeliveryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * P3.2 — uploadReceiptPhoto() must not orphan files on DB rollback.
 *
 * Covers:
 *  (a) Happy path — file is written to the local disk and a DeliveryProof row
 *      is created.
 *  (b) Rollback path — when the DB transaction fails after the file is already
 *      stored on disk, the file must be deleted (not orphaned).
 */
class DeliveryUploadTest extends TestCase
{
    use RefreshDatabase;

    private DeliveryService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
        $this->svc = app(DeliveryService::class);
    }

    // ─── (a) Happy path ───────────────────────────────────────────────────────

    public function test_upload_receipt_photo_stores_file_on_local_disk(): void
    {
        $user     = $this->makeUser();
        $delivery = $this->seedDelivery($user, 'delivered');
        $file     = UploadedFile::fake()->image('receipt.jpg');

        $result = $this->svc->uploadReceiptPhoto($delivery, $file, $user);

        $this->assertNotNull($result->receipt_photo_path);
        Storage::disk('local')->assertExists($result->receipt_photo_path);
    }

    public function test_upload_receipt_photo_creates_delivery_proof_row(): void
    {
        $user     = $this->makeUser();
        $delivery = $this->seedDelivery($user, 'delivered');
        $file     = UploadedFile::fake()->image('receipt.jpg');

        $this->svc->uploadReceiptPhoto($delivery, $file, $user);

        $this->assertDatabaseHas('delivery_proofs', [
            'delivery_id' => $delivery->id,
            'proof_type'  => 'photo',
        ]);
    }

    // ─── (b) Rollback path — file must be cleaned up on DB failure ────────────

    /**
     * Force a mid-transaction DB failure by deleting the delivery row before
     * the call. The delivery model still has its id in memory, so the file
     * store (which happens BEFORE the transaction in the fixed code) succeeds.
     * When the transaction opens and tries to write delivery_proofs (which has
     * a NOT NULL FK on delivery_id pointing to the now-deleted row), SQLite
     * will raise a constraint violation.
     *
     * The fixed service must catch this, delete the already-stored file, and
     * re-throw.
     */
    public function test_upload_receipt_photo_deletes_file_on_transaction_rollback(): void
    {
        $user     = $this->makeUser();
        $delivery = $this->seedDelivery($user, 'delivered');
        $file     = UploadedFile::fake()->image('receipt.jpg');

        // Delete the delivery from DB so the FK on delivery_proofs.delivery_id
        // fails — but keep the model in memory so the status guard passes and
        // the file store (outside the transaction) runs first.
        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = ON');
        Delivery::withoutGlobalScopes()->where('id', $delivery->id)->delete();

        $caughtException = null;
        try {
            $this->svc->uploadReceiptPhoto($delivery, $file, $user);
        } catch (\Throwable $e) {
            $caughtException = $e;
        }

        // An exception must have been thrown (transaction failed).
        $this->assertNotNull($caughtException, 'Expected an exception from the failing transaction.');

        // The uploaded file must NOT remain on disk (no orphan).
        // Derive where the file would have been stored.
        $filesOnDisk = Storage::disk('local')->allFiles();
        $this->assertEmpty(
            $filesOnDisk,
            'Orphaned file detected on local disk after transaction rollback: ' . implode(', ', $filesOnDisk),
        );
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeUser(): User
    {
        $role = Role::create([
            'name'        => 'Upload Test Role ' . uniqid(),
            'slug'        => 'upload_test_' . uniqid(),
            'description' => 'Test',
        ]);
        $perm = Permission::firstOrCreate(
            ['slug' => 'supply_chain.deliveries.create'],
            ['name' => 'Create Delivery', 'module' => 'supply_chain'],
        );
        $role->permissions()->syncWithoutDetaching([$perm->id]);

        return User::factory()->create(['role_id' => $role->id]);
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
        return SalesOrder::create([
            'so_number'    => 'SO-TEST-' . uniqid(),
            'customer_id'  => $customer->id,
            'date'         => now()->toDateString(),
            'subtotal'     => '10000.00',
            'vat_amount'   => '1200.00',
            'total_amount' => '11200.00',
            'status'       => 'confirmed',
            'created_by'   => $user->id,
        ]);
    }

    private function seedDelivery(User $user, string $status = 'delivered'): Delivery
    {
        $so = $this->seedSalesOrder($user);
        return Delivery::create([
            'delivery_number' => 'DEL-TEST-' . uniqid(),
            'sales_order_id'  => $so->id,
            'status'          => $status,
            'scheduled_date'  => now()->toDateString(),
            'created_by'      => $user->id,
        ]);
    }
}

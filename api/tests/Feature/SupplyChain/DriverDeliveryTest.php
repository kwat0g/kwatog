<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\SupplyChain\Models\Delivery;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * T2.5 — Driver self-service delivery routes.
 *
 * Covers ownership scoping, allowed status transitions (scheduled→loading→
 * in_transit→delivered), block on confirm, cross-driver 404, receipt upload,
 * and unauthenticated 401.
 */
class DriverDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local'); // uploadReceiptPhoto writes to 'local' disk
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function driver(?string $email = null): User
    {
        $role = Role::query()->where('slug', 'driver')->firstOrFail();
        return User::factory()->create([
            'role_id'   => $role->id,
            'email'     => $email ?? ('driver+' . uniqid() . '@t.test'),
            'is_active' => true,
        ]);
    }

    /**
     * Build a minimal delivery owned by $driver. Keep so_number short to avoid
     * varchar(20) `so_number` truncation (the bug that breaks other SupplyChain
     * tests). 'SO-T-' + 5-char hex = 10 chars, well under varchar(20).
     */
    private function deliveryFor(User $driver, string $status = 'scheduled'): Delivery
    {
        $customer = Customer::create([
            'name'      => 'Test Customer ' . uniqid(),
            'is_active' => true,
        ]);

        $so = SalesOrder::create([
            'so_number'    => 'SO-T-' . substr(uniqid(), -5),
            'customer_id'  => $customer->id,
            'date'         => now()->toDateString(),
            'subtotal'     => '10000.00',
            'vat_amount'   => '1200.00',
            'total_amount' => '11200.00',
            'status'       => 'confirmed',
            'created_by'   => $driver->id,
        ]);

        return Delivery::create([
            'delivery_number' => 'DLV-T-' . substr(uniqid(), -5),
            'sales_order_id'  => $so->id,
            'driver_id'       => $driver->id,
            'scheduled_date'  => now()->toDateString(),
            'status'          => $status,
            'created_by'      => $driver->id,
        ]);
    }

    // ─── Tests ───────────────────────────────────────────────────────────────

    public function test_driver_sees_only_own_deliveries(): void
    {
        $a = $this->driver();
        $b = $this->driver();
        $this->deliveryFor($a);
        $this->deliveryFor($b);

        $r = $this->actingAs($a)->getJson('/api/v1/driver/deliveries');
        $r->assertOk();
        $this->assertCount(1, $r->json('data'));
    }

    public function test_default_list_excludes_finalised(): void
    {
        $a = $this->driver();
        $this->deliveryFor($a, 'scheduled');
        $this->deliveryFor($a, 'confirmed');
        $this->deliveryFor($a, 'cancelled');

        $r = $this->actingAs($a)->getJson('/api/v1/driver/deliveries');
        $r->assertOk();
        $this->assertCount(1, $r->json('data'));
    }

    public function test_transition_scheduled_to_loading(): void
    {
        $a = $this->driver();
        $d = $this->deliveryFor($a, 'scheduled');

        $r = $this->actingAs($a)->patchJson("/api/v1/driver/deliveries/{$d->hash_id}/status", [
            'status' => 'loading',
        ]);

        $r->assertOk();
        $this->assertSame('loading', $d->fresh()->status->value);
    }

    public function test_transition_loading_to_in_transit(): void
    {
        $a = $this->driver();
        $d = $this->deliveryFor($a, 'loading');

        $r = $this->actingAs($a)->patchJson("/api/v1/driver/deliveries/{$d->hash_id}/status", [
            'status' => 'in_transit',
        ]);

        $r->assertOk();
        $this->assertSame('in_transit', $d->fresh()->status->value);
    }

    public function test_transition_in_transit_to_delivered_stamps_arrival(): void
    {
        $a = $this->driver();
        $d = $this->deliveryFor($a, 'in_transit');

        $r = $this->actingAs($a)->patchJson("/api/v1/driver/deliveries/{$d->hash_id}/status", [
            'status' => 'delivered',
        ]);

        $r->assertOk();
        $this->assertNotNull($d->fresh()->delivered_at);
    }

    public function test_driver_cannot_skip_steps(): void
    {
        $a = $this->driver();
        $d = $this->deliveryFor($a, 'scheduled');

        $r = $this->actingAs($a)->patchJson("/api/v1/driver/deliveries/{$d->hash_id}/status", [
            'status' => 'delivered',
        ]);

        $r->assertStatus(422);
    }

    public function test_driver_cannot_confirm(): void
    {
        $a = $this->driver();
        $d = $this->deliveryFor($a, 'delivered');

        // 'confirmed' is not in the validator allow-list — should fail at validation.
        $r = $this->actingAs($a)->patchJson("/api/v1/driver/deliveries/{$d->hash_id}/status", [
            'status' => 'confirmed',
        ]);

        $r->assertStatus(422);
    }

    public function test_other_driver_delivery_is_404(): void
    {
        $a = $this->driver();
        $b = $this->driver();
        $d = $this->deliveryFor($b, 'scheduled');

        $r = $this->actingAs($a)->patchJson("/api/v1/driver/deliveries/{$d->hash_id}/status", [
            'status' => 'loading',
        ]);

        $r->assertStatus(404);
    }

    public function test_receipt_upload(): void
    {
        $a = $this->driver();
        $d = $this->deliveryFor($a, 'delivered');

        $r = $this->actingAs($a)->post("/api/v1/driver/deliveries/{$d->hash_id}/receipt", [
            'photo' => UploadedFile::fake()->image('receipt.jpg', 400, 600),
        ]);

        $r->assertOk();
        $this->assertSame(1, $d->fresh()->proofs()->count());
    }

    public function test_unauthenticated_is_401(): void
    {
        $this->getJson('/api/v1/driver/deliveries')->assertStatus(401);
    }
}

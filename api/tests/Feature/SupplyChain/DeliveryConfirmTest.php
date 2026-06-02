<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryProof;
use App\Modules\SupplyChain\Services\DeliveryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P3.1 — confirm() TOCTOU double-confirm guard.
 *
 * Covers:
 *  (a) Confirming an already-confirmed delivery is a no-op — no second draft
 *      invoice is created and no exception is raised.
 *  (b) A delivery with no proof of delivery cannot be confirmed.
 *  (c) Calling confirm() twice in sequence only produces one confirmation effect
 *      (simulates the race path without true concurrency).
 */
class DeliveryConfirmTest extends TestCase
{
    use RefreshDatabase;

    private DeliveryService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->svc = app(DeliveryService::class);
    }

    // ─── (b) No proof → cannot confirm ───────────────────────────────────────

    public function test_confirm_requires_at_least_one_proof(): void
    {
        $user     = $this->makeUser();
        $delivery = $this->seedDelivery($user, 'delivered');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('proof of delivery');

        $this->svc->confirm($delivery, $user);
    }

    // ─── (a) Already-confirmed is a no-op ────────────────────────────────────

    public function test_confirm_already_confirmed_is_noop(): void
    {
        $user     = $this->makeUser();
        $delivery = $this->seedDelivery($user, 'confirmed');

        // Should not throw.
        $result = $this->svc->confirm($delivery, $user);

        $this->assertSame('confirmed', $result->status->value);
    }

    // ─── (c) Double-confirm only produces one effect ──────────────────────────

    /**
     * Simulate the TOCTOU path: call confirm() twice on the same delivered
     * delivery (with a proof). The second call must be a no-op — the delivery
     * stays confirmed and no second invoice is created.
     *
     * In production the race would be two concurrent HTTP requests. Here we
     * reproduce the same logical path (second call sees confirmed status) by
     * calling sequentially. The fix ensures the status is re-read under a
     * lock inside the transaction so any concurrent second call would also
     * return early.
     */
    public function test_double_confirm_produces_only_one_confirmation_effect(): void
    {
        $user     = $this->makeUser();
        $delivery = $this->seedDelivery($user, 'delivered');

        // Add a proof so the guard passes.
        DeliveryProof::create([
            'delivery_id' => $delivery->id,
            'proof_type'  => 'photo',
            'file_name'   => 'receipt.jpg',
            'file_path'   => 'deliveries/' . $delivery->id . '/receipt.jpg',
            'mime_type'   => 'image/jpeg',
            'uploaded_by' => $user->id,
        ]);

        // Count invoices before.
        $invoiceCountBefore = \App\Modules\Accounting\Models\Invoice::count();

        // First confirm — should succeed and (try to) create a draft invoice.
        $first = $this->svc->confirm($delivery, $user);
        $this->assertSame('confirmed', $first->status->value);
        $this->assertNotNull($first->confirmed_at);

        // Record state after first confirm.
        $invoiceCountAfterFirst = \App\Modules\Accounting\Models\Invoice::count();

        // Second confirm — the delivery is now confirmed; must be a no-op.
        $second = $this->svc->confirm($delivery->fresh(), $user);
        $this->assertSame('confirmed', $second->status->value);

        // No new invoice must have been created on the second call.
        $invoiceCountAfterSecond = \App\Modules\Accounting\Models\Invoice::count();
        $this->assertSame(
            $invoiceCountAfterFirst,
            $invoiceCountAfterSecond,
            'A second confirm() call must not create an additional invoice.',
        );

        // confirmed_at and confirmed_by must not have changed.
        $fresh = $delivery->fresh();
        $this->assertTrue(
            $fresh->confirmed_at->eq($first->confirmed_at),
            'confirmed_at must not be overwritten on a no-op re-confirm.',
        );
    }

    /**
     * Verifies the lock-reload path explicitly: after the first confirm()
     * persists `status = confirmed`, a second call using the same stale model
     * instance (which still shows `status = delivered` in memory) must still
     * be treated as a no-op by the locked re-read inside the transaction.
     */
    public function test_stale_model_double_confirm_is_noop(): void
    {
        $user     = $this->makeUser();
        $delivery = $this->seedDelivery($user, 'delivered');

        DeliveryProof::create([
            'delivery_id' => $delivery->id,
            'proof_type'  => 'signed_dr',
            'file_name'   => 'dr.pdf',
            'file_path'   => 'deliveries/' . $delivery->id . '/dr.pdf',
            'mime_type'   => 'application/pdf',
            'uploaded_by' => $user->id,
        ]);

        // Confirm once — delivery now confirmed in DB.
        $this->svc->confirm($delivery, $user);

        // Reload the model so in-memory status is fresh, then force it back to
        // "delivered" so the outer guards do NOT short-circuit (simulates the
        // stale model that the second concurrent request would hold at the point
        // it entered confirm() before the first request committed).
        $staleModel = $delivery->fresh();
        $staleModel->forceFill(['status' => \App\Modules\SupplyChain\Enums\DeliveryStatus::Delivered]);

        $invoiceCountBefore = \App\Modules\Accounting\Models\Invoice::count();

        // The fix: inside the transaction a lockForUpdate re-read sees
        // `status = confirmed` and returns early — no extra save, no extra invoice.
        $result = $this->svc->confirm($staleModel, $user);

        $this->assertSame('confirmed', $result->status->value);
        $this->assertSame(
            $invoiceCountBefore,
            \App\Modules\Accounting\Models\Invoice::count(),
            'Lock-reload path must not create a second invoice for a stale model.',
        );
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeUser(): User
    {
        $role = Role::create([
            'name'        => 'Confirm Test Role ' . uniqid(),
            'slug'        => 'confirm_test_' . uniqid(),
            'description' => 'Test',
        ]);
        $perm = Permission::firstOrCreate(
            ['slug' => 'supply_chain.deliveries.confirm'],
            ['name' => 'Confirm Delivery', 'module' => 'supply_chain'],
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

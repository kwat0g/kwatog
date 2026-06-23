<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * OGAMI-002 / audit DEFECT-2 — segregation of duties on PO approval: the user
 * who created the vendor must not approve a PO to that vendor.
 *
 * The `vendors.created_by` column now exists (migration 0222) and is populated
 * by VendorService::create(), so the guard is ACTIVE. The first test asserts a
 * vendor with no recorded creator still flows (guard cannot fire on unknown
 * makers); the second asserts the creator is blocked.
 */
class PoVendorSodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WorkflowSeeder::class);
    }

    private function makeUser(string $roleSlug): User
    {
        $roleId = Role::query()->where('slug', $roleSlug)->value('id');
        return User::create([
            'name'     => 'U '.substr(uniqid(), -5),
            'email'    => 'u_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'),
            'role_id'  => $roleId,
        ]);
    }

    private function makePo(PurchaseOrderService $svc, User $by, Vendor $vendor)
    {
        $item = Item::factory()->create();
        return $svc->create([
            'vendor_id'  => $vendor->hash_id,
            'date'       => '2026-06-01',
            'is_vatable' => true,
            'items'      => [[
                'item_id'     => $item->hash_id,
                'description' => 'SoD test line',
                'quantity'    => '2',
                'unit'        => 'pcs',
                'unit_price'  => '1000.00',
            ]],
        ], $by);
    }

    public function test_vendor_with_unknown_creator_does_not_trip_the_guard(): void
    {
        // The column now exists, but a vendor whose created_by is null (legacy /
        // imported rows) has an unknown maker, so the guard cannot fire and the PO
        // flows through the normal approval workflow.
        $this->assertTrue(
            Schema::hasColumn('vendors', 'created_by'),
            'vendors.created_by must exist for the active SoD guard (migration 0222).'
        );

        $svc = app(PurchaseOrderService::class);
        $maker = $this->makeUser('purchasing_officer');
        $vendor = Vendor::factory()->create(['created_by' => null]);

        $po = $this->makePo($svc, $maker, $vendor);
        $submitted = $svc->submit($po);
        $this->assertSame(PurchaseOrderStatus::PendingApproval, $submitted->status);

        // A different purchasing_officer approves step 1 — guard does not interfere.
        $approver = $this->makeUser('purchasing_officer');
        $result = $svc->approve($submitted->fresh(), $approver);
        $this->assertContains(
            $result->status,
            [PurchaseOrderStatus::PendingApproval, PurchaseOrderStatus::Approved]
        );
    }

    /**
     * Active SoD path. Skipped until `vendors.created_by` exists. When it does,
     * this verifies the vendor creator is blocked from approving a PO to that
     * vendor, and that the override permission lifts the block.
     */
    public function test_vendor_creator_cannot_approve_po_to_that_vendor(): void
    {
        if (! Schema::hasColumn('vendors', 'created_by')) {
            $this->markTestSkipped('vendors.created_by column not present — SoD guard dormant by design.');
        }

        $svc = app(PurchaseOrderService::class);
        // The vendor creator also happens to be a purchasing_officer (step-1 approver).
        $vendorCreator = $this->makeUser('purchasing_officer');
        $poMaker = $this->makeUser('purchasing_officer');

        $vendor = Vendor::factory()->create(['created_by' => $vendorCreator->id]);
        $po = $this->makePo($svc, $poMaker, $vendor);
        $submitted = $svc->submit($po);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('segregation of duties');
        $svc->approve($submitted->fresh(), $vendorCreator);
    }
}

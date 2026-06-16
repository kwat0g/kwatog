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
 * OGAMI-002 — segregation of duties on PO approval: the user who created the
 * vendor must not approve a PO to that vendor.
 *
 * NOTE: the `vendors` table currently has NO `created_by` column, so this guard
 * is dormant (it skips gracefully). The first test documents that gap. The
 * active-path test self-activates the day a `created_by` column is added — see
 * the report for the schema follow-up the orchestrator must own.
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

    public function test_vendor_sod_guard_is_dormant_without_created_by_column(): void
    {
        // Documents the verified schema gap: the guard cannot fire today because
        // there is nothing recording who created a vendor.
        $this->assertFalse(
            Schema::hasColumn('vendors', 'created_by'),
            'vendors.created_by now exists — enable the active SoD path (drop this assertion).'
        );

        // With the column absent, a vendor creator approving their own PO must NOT
        // be blocked by the SoD guard (it skips gracefully). The PO still flows
        // through the normal approval workflow.
        $svc = app(PurchaseOrderService::class);
        $maker = $this->makeUser('purchasing_officer');
        $vendor = Vendor::factory()->create();

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

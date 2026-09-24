<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Policies\PurchaseOrderAccessPolicy;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PU-13 — PurchaseOrderAccessPolicy::actionsFor() must mirror the approval
 * service's guards exactly. The action map controls button visibility on the
 * detail page; a mismatch means a hidden button and a refused request can
 * disagree.
 *
 * Tests verify:
 *   1. At step 1: finance_officer (not vendor creator) → can_approve = true
 *   2. At step 1: vice_president → can_approve = false (not the current step)
 *   3. After step 1 approval: vice_president → can_approve = true (now current)
 *   4. Vendor creator at step 1 → can_approve = false (SoD blocks)
 *   5. API approve/reject match the action map exactly
 */
class PurchaseOrderActionsMapTest extends TestCase
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
        return User::factory()->create(['role_id' => $roleId]);
    }

    private function makePo(PurchaseOrderService $svc, User $by, Vendor $vendor, float $amount = 100000): PurchaseOrder
    {
        $item = Item::factory()->create();
        // POs must originate from an approved PR (PR → approved → PO).
        $pr = PurchaseRequest::factory()->create();
        $pr->forceFill(['status' => PurchaseRequestStatus::Approved->value])->save();

        $unitPrice = (string) ($amount / 2);

        return $svc->create([
            'vendor_id'           => $vendor->hash_id,
            'purchase_request_id' => $pr->id,
            'date'                => '2026-06-01',
            'is_vatable'          => false,
            'items'               => [[
                'item_id'     => $item->hash_id,
                'description' => 'VN-T-'.substr(uniqid(), -5),
                'quantity'    => '2',
                'unit'        => 'pcs',
                'unit_price'  => $unitPrice,
            ]],
        ], $by);
    }

    private function getPendingActions(User $user, PurchaseOrder $po): array
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/purchasing/purchase-orders/'.$po->hash_id)
            ->assertOk()
            ->json('data.actions');
    }

    public function test_step1_finance_officer_not_vendor_creator_sees_approve(): void
    {
        $svc = app(PurchaseOrderService::class);
        $policy = app(PurchaseOrderAccessPolicy::class);
        $financeA = $this->makeUser('finance_officer');
        $financeB = $this->makeUser('finance_officer');
        $vendor = Vendor::factory()->create(['created_by' => null]);

        $po = $this->makePo($svc, $financeA, $vendor, 100000);
        $submitted = $svc->submit($po);

        $actions = $policy->actionsFor($financeB, $submitted->fresh());

        $this->assertTrue($actions['can_approve']);
        $this->assertTrue($actions['can_reject']);
    }

    public function test_step1_vice_president_cannot_approve_future_step(): void
    {
        $svc = app(PurchaseOrderService::class);
        $policy = app(PurchaseOrderAccessPolicy::class);
        $finance = $this->makeUser('finance_officer');
        $vp = $this->makeUser('vice_president');
        $vendor = Vendor::factory()->create(['created_by' => null]);

        $po = $this->makePo($svc, $finance, $vendor, 100000);
        $submitted = $svc->submit($po);

        $actions = $policy->actionsFor($vp, $submitted->fresh());

        $this->assertFalse($actions['can_approve'], 'VP should not see approve at step 1 (finance is current)');
        $this->assertFalse($actions['can_reject'], 'VP should not see reject at step 1 (finance is current)');
    }

    public function test_after_step1_approval_step2_vp_can_approve(): void
    {
        $svc = app(PurchaseOrderService::class);
        $policy = app(PurchaseOrderAccessPolicy::class);
        $financeA = $this->makeUser('finance_officer');
        $financeB = $this->makeUser('finance_officer');
        $vp = $this->makeUser('vice_president');
        $vendor = Vendor::factory()->create(['created_by' => null]);

        $po = $this->makePo($svc, $financeA, $vendor, 100000);
        $submitted = $svc->submit($po);

        // Finance approves step 1
        $approved = $svc->approve($submitted->fresh(), $financeB);

        // VP should now see approve at step 2
        $actions = $policy->actionsFor($vp, $approved->fresh());

        $this->assertTrue($actions['can_approve'], 'VP should see approve at step 2 after finance approved');
        $this->assertTrue($actions['can_reject'], 'VP should see reject at step 2 after finance approved');
    }

    public function test_vendor_creator_at_step1_cannot_approve_sod(): void
    {
        $svc = app(PurchaseOrderService::class);
        $policy = app(PurchaseOrderAccessPolicy::class);
        $vendorCreator = $this->makeUser('finance_officer');
        $buyer = $this->makeUser('purchasing_officer');
        $vendor = Vendor::factory()->create(['created_by' => $vendorCreator->id]);

        $po = $this->makePo($svc, $buyer, $vendor, 100000);
        $submitted = $svc->submit($po);

        $actions = $policy->actionsFor($vendorCreator, $submitted->fresh());

        $this->assertFalse($actions['can_approve'], 'Vendor creator should not see approve due to SoD');
        $this->assertTrue($actions['can_reject'], 'Vendor creator can reject (no SoD check on reject)');
    }

    public function test_vendor_creator_approval_api_returns_403(): void
    {
        $svc = app(PurchaseOrderService::class);
        $vendorCreator = $this->makeUser('finance_officer');
        $buyer = $this->makeUser('purchasing_officer');
        $vendor = Vendor::factory()->create(['created_by' => $vendorCreator->id]);

        $po = $this->makePo($svc, $buyer, $vendor, 100000);
        $submitted = $svc->submit($po);

        $this->actingAs($vendorCreator, 'sanctum')
            ->patchJson('/api/v1/purchasing/purchase-orders/'.$submitted->hash_id.'/approve', ['remarks' => 'test'])
            ->assertForbidden()
            ->assertJsonPath('message', 'You cannot approve a purchase order to a vendor you created (segregation of duties).');
    }

    public function test_finance_approval_at_step1_flows_to_step2(): void
    {
        $svc = app(PurchaseOrderService::class);
        $buyer = $this->makeUser('purchasing_officer');
        $financeA = $this->makeUser('finance_officer');
        $financeB = $this->makeUser('finance_officer');
        $vendor = Vendor::factory()->create(['created_by' => null]);

        $po = $this->makePo($svc, $buyer, $vendor, 100000);
        $submitted = $svc->submit($po);

        $response = $this->actingAs($financeA, 'sanctum')
            ->patchJson('/api/v1/purchasing/purchase-orders/'.$submitted->hash_id.'/approve', ['remarks' => 'looks good'])
            ->assertOk();

        $this->assertSame(PurchaseOrderStatus::PendingApproval->value, $response->json('data.status'));
    }

    public function test_finance_rejection_at_step1_cancels_po(): void
    {
        $svc = app(PurchaseOrderService::class);
        $buyer = $this->makeUser('purchasing_officer');
        $finance = $this->makeUser('finance_officer');
        $vendor = Vendor::factory()->create(['created_by' => null]);

        $po = $this->makePo($svc, $buyer, $vendor, 100000);
        $submitted = $svc->submit($po);

        $response = $this->actingAs($finance, 'sanctum')
            ->patchJson('/api/v1/purchasing/purchase-orders/'.$submitted->hash_id.'/reject', ['reason' => 'incomplete'])
            ->assertOk();

        $this->assertSame(PurchaseOrderStatus::Cancelled->value, $response->json('data.status'));
    }

    public function test_vp_rejection_at_step2_cancels_po(): void
    {
        $svc = app(PurchaseOrderService::class);
        $buyer = $this->makeUser('purchasing_officer');
        $financeA = $this->makeUser('finance_officer');
        $vp = $this->makeUser('vice_president');
        $vendor = Vendor::factory()->create(['created_by' => null]);

        $po = $this->makePo($svc, $buyer, $vendor, 100000);
        $submitted = $svc->submit($po);

        // Finance approves step 1
        $approved = $svc->approve($submitted->fresh(), $financeA);

        // VP rejects at step 2
        $response = $this->actingAs($vp, 'sanctum')
            ->patchJson('/api/v1/purchasing/purchase-orders/'.$approved->hash_id.'/reject', ['reason' => 'budget exceeded'])
            ->assertOk();

        $this->assertSame(PurchaseOrderStatus::Cancelled->value, $response->json('data.status'));
    }

    public function test_two_finance_officers_first_at_step1_second_cannot_act(): void
    {
        $svc = app(PurchaseOrderService::class);
        $financeA = $this->makeUser('finance_officer');
        $financeB = $this->makeUser('finance_officer');
        $vendor = Vendor::factory()->create(['created_by' => null]);

        $po = $this->makePo($svc, $financeA, $vendor, 100000);
        $submitted = $svc->submit($po);

        // FinanceA (the submitter) should not see approve
        $actionsA = $this->getPendingActions($financeA, $submitted->fresh());
        $this->assertFalse($actionsA['can_approve']);
        $this->assertFalse($actionsA['can_reject']);

        // FinanceB should see approve
        $actionsB = $this->getPendingActions($financeB, $submitted->fresh());
        $this->assertTrue($actionsB['can_approve']);
        $this->assertTrue($actionsB['can_reject']);
    }
}

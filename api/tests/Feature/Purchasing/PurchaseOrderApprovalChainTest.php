<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Models\WorkflowDefinition;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PS-01 first pinned the purchase_order chain's row scope; the 2026-09-11
 * redesign moved the chain from purchasing_officer → finance_officer →
 * vice_president to finance_officer → vice_president.
 *
 * The old step 1 named the buyer's own role. purchasing_officer is the only
 * role holding purchasing.po.create, so a buyer who raised a PO was also the
 * only role its first step accepted — ApprovalService's maker ≠ checker guard
 * then refused them and the PO stalled forever (a sole buyer had no second
 * holder to approve). Finance and the VP create no POs, so the chain is now
 * money-only and can never strand the submitter.
 */
class PurchaseOrderApprovalChainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(WorkflowSeeder::class);
    }

    private function user(string $roleSlug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
        ]);
    }

    private function draftPo(User $creator, string $total = '10000.00'): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_number'    => 'PO-'.substr(uniqid(), -6),
            'vendor_id'    => Vendor::create([
                'name'               => 'Vendor-'.substr(uniqid(), -5),
                'payment_terms_days' => 30,
            ])->id,
            'date'         => now()->toDateString(),
            'total_amount' => $total,
            'created_by'   => $creator->id,
        ]);
        $po->forceFill(['status' => PurchaseOrderStatus::Draft->value])->save();

        return $po;
    }

    public function test_the_chain_no_longer_names_the_buyer_role(): void
    {
        $roles = collect(WorkflowDefinition::query()
            ->where('workflow_type', 'purchase_order')
            ->value('steps'))
            ->pluck('role')
            ->all();

        $this->assertSame(['finance_officer', 'vice_president'], $roles);
        $this->assertNotContains('purchasing_officer', $roles);
    }

    /**
     * The regression: a purchasing officer submits a PO and the chain advances
     * to Finance (previously it stalled at the buyer's own step).
     */
    public function test_purchasing_officer_can_submit_and_finance_approves(): void
    {
        $buyer = $this->user('purchasing_officer');
        $po = $this->draftPo($buyer);
        $pending = app(PurchaseOrderService::class)->submit($po);

        $this->assertSame(PurchaseOrderStatus::PendingApproval, $pending->status);
        $this->assertSame('finance_officer', DB::table('approval_records')
            ->where('approvable_type', $po->getMorphClass())
            ->where('approvable_id', $po->id)
            ->orderBy('step_order')
            ->value('role_slug'));

        $finance = $this->user('finance_officer');
        $approved = app(PurchaseOrderService::class)->approve($pending->fresh(), $finance);

        $this->assertSame(PurchaseOrderStatus::Approved, $approved->status);
        $this->assertSame($finance->id, (int) $approved->approved_by);
    }

    public function test_high_value_po_requires_finance_then_vp(): void
    {
        $buyer = $this->user('purchasing_officer');
        $pending = app(PurchaseOrderService::class)->submit($this->draftPo($buyer, '60000.00'));

        $afterFinance = app(PurchaseOrderService::class)
            ->approve($pending->fresh(), $this->user('finance_officer'));
        $this->assertSame(PurchaseOrderStatus::PendingApproval, $afterFinance->status);

        $approved = app(PurchaseOrderService::class)
            ->approve($afterFinance->fresh(), $this->user('vice_president'));
        $this->assertSame(PurchaseOrderStatus::Approved, $approved->status);
    }

    public function test_finance_officer_sees_the_pending_po_in_the_list(): void
    {
        $buyer = $this->user('purchasing_officer');
        app(PurchaseOrderService::class)->submit($this->draftPo($buyer));
        $finance = $this->user('finance_officer');

        $response = $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/purchasing/purchase-orders?per_page=100')
            ->assertOk();

        $numbers = array_map(static fn (array $row): string => (string) $row['po_number'], $response->json('data'));
        $this->assertCount(1, $numbers);
    }

    public function test_role_without_the_approve_permission_is_rejected(): void
    {
        $buyer = $this->user('purchasing_officer');
        $pending = app(PurchaseOrderService::class)->submit($this->draftPo($buyer));
        $warehouse = $this->user('warehouse_staff');

        $this->actingAs($warehouse, 'sanctum')
            ->patchJson('/api/v1/purchasing/purchase-orders/'.$pending->hash_id.'/approve')
            ->assertForbidden();

        $this->assertSame('pending', DB::table('approval_records')
            ->where('approvable_type', $pending->getMorphClass())
            ->where('approvable_id', $pending->id)
            ->orderBy('step_order')
            ->value('action'));
    }

    public function test_buyer_holding_po_approve_still_cannot_self_approve(): void
    {
        $buyer = $this->user('purchasing_officer');
        $pending = app(PurchaseOrderService::class)->submit($this->draftPo($buyer));

        // purchasing_officer holds purchasing.po.approve but is not the pending
        // step role; the self-approval guard refuses them either way.
        $this->actingAs($buyer, 'sanctum')
            ->patchJson('/api/v1/purchasing/purchase-orders/'.$pending->hash_id.'/approve')
            ->assertForbidden();

        $this->assertSame('pending', DB::table('approval_records')
            ->where('approvable_type', $pending->getMorphClass())
            ->where('approvable_id', $pending->id)
            ->orderBy('step_order')
            ->value('action'));
    }
}

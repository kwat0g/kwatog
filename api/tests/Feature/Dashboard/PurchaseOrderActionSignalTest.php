<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Common\Models\ApprovalRecord;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Enums\PurchaseOrderResponseStatus;
use App\Modules\Purchasing\Enums\PurchaseOrderResponseType;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderResponse;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PurchaseOrderActionSignalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
    }

    public function test_action_badge_matches_the_actionable_list_and_separates_action_types(): void
    {
        $actor = $this->userWithPermissions('po_action_all', [
            'purchasing.view',
            'purchasing.po.approve',
            'purchasing.po.send',
            'purchasing.po.responses.resolve',
        ]);

        $approval = $this->po(PurchaseOrderStatus::PendingApproval, User::factory()->create()->id);
        $this->approvalStep($approval, 1, 'po_action_all');

        $laterStep = $this->po(PurchaseOrderStatus::PendingApproval, User::factory()->create()->id);
        $this->approvalStep($laterStep, 1, 'finance_officer');
        $this->approvalStep($laterStep, 2, 'po_action_all');

        $selfSubmitted = $this->po(PurchaseOrderStatus::PendingApproval, $actor->id);
        $this->approvalStep($selfSubmitted, 1, 'po_action_all');

        $send = $this->po(PurchaseOrderStatus::Approved, $actor->id);

        $supplierResponse = $this->po(PurchaseOrderStatus::SupplierProposed, $actor->id);
        $this->response($supplierResponse);

        $waitingOnSupplier = $this->po(PurchaseOrderStatus::Sent, $actor->id);

        $expected = [$approval->po_number, $send->po_number, $supplierResponse->po_number];
        $list = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/purchasing/purchase-orders?signal=action&per_page=100')
            ->assertOk();
        $badgeCount = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data.purchase_order_actions.count');

        $this->assertSame($badgeCount, $list->json('meta.total'));
        $this->assertSame(3, $badgeCount);
        $this->assertEqualsCanonicalizing($expected, collect($list->json('data'))->pluck('po_number')->all());

        foreach ([
            'approval' => $approval,
            'send' => $send,
            'supplier_response' => $supplierResponse,
        ] as $actionType => $purchaseOrder) {
            $this->actingAs($actor, 'sanctum')
                ->getJson('/api/v1/purchasing/purchase-orders?signal=action&action_type='.$actionType)
                ->assertOk()
                ->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.po_number', $purchaseOrder->po_number);
        }

        $this->assertNotContains($laterStep->po_number, $expected);
        $this->assertNotContains($selfSubmitted->po_number, $expected);
        $this->assertNotContains($waitingOnSupplier->po_number, $expected);
        $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/purchasing/purchase-orders?signal=action&action_type=unknown')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_supplier_response_action_respects_purchase_order_row_visibility(): void
    {
        $resolver = $this->userWithPermissions('po_response_resolver', [
            'purchasing.view',
            'purchasing.po.responses.resolve',
        ]);
        $owner = User::factory()->create();
        $own = $this->po(PurchaseOrderStatus::SupplierProposed, $resolver->id);
        $this->response($own);
        $hidden = $this->po(PurchaseOrderStatus::SupplierProposed, $owner->id);
        $this->response($hidden);

        $list = $this->actingAs($resolver, 'sanctum')
            ->getJson('/api/v1/purchasing/purchase-orders?signal=action&action_type=supplier_response')
            ->assertOk();
        $badgeCount = $this->actingAs($resolver, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data.purchase_order_actions.count');

        $this->assertSame(1, $badgeCount);
        $this->assertSame($badgeCount, $list->json('meta.total'));
        $this->assertSame($own->po_number, $list->json('data.0.po_number'));
        $this->actingAs($resolver, 'sanctum')
            ->getJson('/api/v1/purchasing/purchase-orders/'.$hidden->hash_id)
            ->assertForbidden();
    }

    private function userWithPermissions(string $slug, array $permissions): User
    {
        $role = Role::query()->create(['name' => $slug, 'slug' => $slug, 'is_system' => false]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $permissions)->pluck('id')->all());

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function po(PurchaseOrderStatus $status, int $createdBy): PurchaseOrder
    {
        $purchaseOrder = PurchaseOrder::factory()->create(['created_by' => $createdBy]);
        $purchaseOrder->forceFill(['status' => $status])->save();

        return $purchaseOrder;
    }

    private function approvalStep(PurchaseOrder $purchaseOrder, int $step, string $role): void
    {
        ApprovalRecord::query()->create([
            'approvable_type' => $purchaseOrder->getMorphClass(),
            'approvable_id' => $purchaseOrder->id,
            'step_order' => $step,
            'role_slug' => $role,
            'attempt' => 1,
            'action' => 'pending',
            'is_current' => true,
        ]);
    }

    private function response(PurchaseOrder $purchaseOrder): void
    {
        PurchaseOrderResponse::query()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'vendor_id' => $purchaseOrder->vendor_id,
            'response_type' => PurchaseOrderResponseType::Propose,
            'status' => PurchaseOrderResponseStatus::Pending,
        ]);
    }
}

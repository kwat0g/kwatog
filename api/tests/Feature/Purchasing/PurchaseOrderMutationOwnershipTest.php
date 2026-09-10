<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PU-02 — PO mutation endpoints checked only status + route permission, so any
 * permission holder could edit, delete, cancel, send or close ANY buyer's PO.
 * PurchaseOrderAccessPolicy now carries the action layer (creator or the
 * purchasing.po.approve company-wide tier, mirroring the PR policy), enforced
 * inside the locked transaction by PurchaseOrderService.
 */
class PurchaseOrderMutationOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private ?Role $buyerRole = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(WorkflowSeeder::class);
    }

    /**
     * A PO operator with every mutation route permission EXCEPT
     * purchasing.po.approve — the row owner, not the company-wide tier.
     */
    private function buyer(): User
    {
        if ($this->buyerRole === null) {
            $this->buyerRole = Role::create([
                'name' => 'PU-T-'.substr(uniqid(), -5),
                'slug' => 'pu02_'.substr(uniqid(), -5),
                'is_system' => false,
            ]);
            $this->buyerRole->permissions()->sync(
                Permission::query()->whereIn('slug', [
                    'purchasing.view',
                    'purchasing.po.create',
                    'purchasing.po.send',
                    'purchasing.po.manage',
                ])->pluck('id')->all(),
            );
        }

        return User::factory()->create(['role_id' => $this->buyerRole->id]);
    }

    private function officer(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'purchasing_officer')->value('id'),
        ]);
    }

    private function poFor(User $creator, PurchaseOrderStatus $status = PurchaseOrderStatus::Draft): PurchaseOrder
    {
        return PurchaseOrder::factory()->create([
            'created_by' => $creator->id,
            'status'     => $status->value,
            'remarks'    => 'original',
        ]);
    }

    public function test_buyer_cannot_update_or_delete_another_buyers_draft_po(): void
    {
        $victim = $this->buyer();
        $attacker = $this->buyer();
        $po = $this->poFor($victim);

        $this->actingAs($attacker, 'sanctum')
            ->putJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}", ['remarks' => 'mine now'])
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have permission to edit this purchase order.');

        $this->actingAs($attacker, 'sanctum')
            ->deleteJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have permission to delete this purchase order.');

        $fresh = $po->fresh();
        $this->assertSame('original', $fresh->remarks);
        $this->assertFalse($fresh->trashed());
    }

    public function test_buyer_can_update_and_delete_own_draft_po(): void
    {
        $owner = $this->buyer();
        $po = $this->poFor($owner);

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}", ['remarks' => 'mine now'])
            ->assertOk();
        $this->assertSame('mine now', $po->fresh()->remarks);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}")
            ->assertNoContent();
        $this->assertTrue($po->fresh()->trashed());
    }

    public function test_buyer_cannot_cancel_or_send_another_buyers_po_but_can_own(): void
    {
        $victim = $this->buyer();
        $attacker = $this->buyer();
        $approved = $this->poFor($victim, PurchaseOrderStatus::Approved);

        $this->actingAs($attacker, 'sanctum')
            ->patchJson("/api/v1/purchasing/purchase-orders/{$approved->hash_id}/cancel", ['reason' => 'not yours'])
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have permission to cancel this purchase order.');

        $this->actingAs($attacker, 'sanctum')
            ->patchJson("/api/v1/purchasing/purchase-orders/{$approved->hash_id}/send")
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have permission to send this purchase order.');

        $this->assertSame(PurchaseOrderStatus::Approved, $approved->fresh()->status);

        $own = $this->poFor($attacker, PurchaseOrderStatus::Approved);
        $this->actingAs($attacker, 'sanctum')
            ->patchJson("/api/v1/purchasing/purchase-orders/{$own->hash_id}/send")
            ->assertOk();
        $this->assertSame(PurchaseOrderStatus::Sent, $own->fresh()->status);

        $ownDraft = $this->poFor($attacker);
        $this->actingAs($attacker, 'sanctum')
            ->patchJson("/api/v1/purchasing/purchase-orders/{$ownDraft->hash_id}/cancel", ['reason' => 'changed mind'])
            ->assertOk();
        $this->assertSame(PurchaseOrderStatus::Cancelled, $ownDraft->fresh()->status);
    }

    public function test_buyer_cannot_close_another_buyers_received_po_but_can_own(): void
    {
        $victim = $this->buyer();
        $attacker = $this->buyer();
        $received = $this->poFor($victim, PurchaseOrderStatus::Received);

        $this->actingAs($attacker, 'sanctum')
            ->patchJson("/api/v1/purchasing/purchase-orders/{$received->hash_id}/close")
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have permission to close this purchase order.');
        $this->assertSame(PurchaseOrderStatus::Received, $received->fresh()->status);

        $own = $this->poFor($attacker, PurchaseOrderStatus::Received);
        $this->actingAs($attacker, 'sanctum')
            ->patchJson("/api/v1/purchasing/purchase-orders/{$own->hash_id}/close")
            ->assertOk();
        $this->assertSame(PurchaseOrderStatus::Closed, $own->fresh()->status);
    }

    public function test_company_wide_tier_can_act_on_another_buyers_po(): void
    {
        $victim = $this->buyer();
        $officer = $this->officer();

        $draft = $this->poFor($victim);
        $this->actingAs($officer, 'sanctum')
            ->putJson("/api/v1/purchasing/purchase-orders/{$draft->hash_id}", ['remarks' => 'corrected by officer'])
            ->assertOk();
        $this->assertSame('corrected by officer', $draft->fresh()->remarks);

        $approved = $this->poFor($victim, PurchaseOrderStatus::Approved);
        $this->actingAs($officer, 'sanctum')
            ->patchJson("/api/v1/purchasing/purchase-orders/{$approved->hash_id}/cancel", ['reason' => 'superseded'])
            ->assertOk();
        $this->assertSame(PurchaseOrderStatus::Cancelled, $approved->fresh()->status);
    }

    public function test_buyer_cannot_restore_another_buyers_trashed_draft(): void
    {
        $victim = $this->buyer();
        $attacker = $this->buyer();
        $po = $this->poFor($victim);
        $this->actingAs($victim, 'sanctum')
            ->deleteJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}")
            ->assertNoContent();

        $this->actingAs($attacker, 'sanctum')
            ->patchJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}/restore")
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have permission to restore this purchase order.');
        $this->assertTrue(PurchaseOrder::withTrashed()->find($po->id)->trashed());

        $this->actingAs($victim, 'sanctum')
            ->patchJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}/restore")
            ->assertOk();
        $this->assertFalse(PurchaseOrder::withTrashed()->find($po->id)->trashed());

        $officerDraft = $this->poFor($victim);
        $this->actingAs($victim, 'sanctum')
            ->deleteJson("/api/v1/purchasing/purchase-orders/{$officerDraft->hash_id}")
            ->assertNoContent();
        $this->actingAs($this->officer(), 'sanctum')
            ->patchJson("/api/v1/purchasing/purchase-orders/{$officerDraft->hash_id}/restore")
            ->assertOk();
        $this->assertFalse(PurchaseOrder::withTrashed()->find($officerDraft->id)->trashed());
    }

    public function test_status_guards_still_fire_for_the_owner(): void
    {
        $owner = $this->buyer();

        $draft = $this->poFor($owner);
        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/purchasing/purchase-orders/{$draft->hash_id}/send")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only approved POs can be marked as sent.');

        $approved = $this->poFor($owner, PurchaseOrderStatus::Approved);
        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/purchasing/purchase-orders/{$approved->hash_id}/close")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only fully received POs can be closed.');
        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/purchasing/purchase-orders/{$approved->hash_id}", ['remarks' => 'late edit'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only draft POs can be edited.');

        $this->assertSame(PurchaseOrderStatus::Draft, $draft->fresh()->status);
        $this->assertSame(PurchaseOrderStatus::Approved, $approved->fresh()->status);
    }

    public function test_approval_flow_is_unaffected_for_non_creators(): void
    {
        $buyer = $this->buyer();
        $officer = $this->officer();
        $po = $this->poFor($buyer);

        $this->actingAs($buyer, 'sanctum')
            ->patchJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}/submit")
            ->assertOk();
        $this->assertSame(PurchaseOrderStatus::PendingApproval, $po->fresh()->status);

        $this->actingAs($officer, 'sanctum')
            ->patchJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}/approve", ['remarks' => 'step one'])
            ->assertOk();

        $this->assertDatabaseHas('approval_records', [
            'approvable_id' => $po->id,
            'approver_id'   => $officer->id,
            'action'        => 'approved',
        ]);
    }

    public function test_budget_acknowledgement_refuses_strangers_with_only_budget_permission(): void
    {
        $buyer = $this->buyer();
        $po = $this->poFor($buyer);
        $po->forceFill([
            'budget_warning_level'   => 'exhausted',
            'budget_warning_message' => 'Department budget exhausted.',
        ])->save();

        $this->actingAs($buyer, 'sanctum')
            ->patchJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}/acknowledge-budget")
            ->assertForbidden();

        $strangerRole = Role::create([
            'name' => 'PU-T-'.substr(uniqid(), -5),
            'slug' => 'pu02f'.substr(uniqid(), -5),
            'is_system' => false,
        ]);
        $strangerRole->permissions()->sync(
            Permission::query()->whereIn('slug', ['purchasing.view', 'budgeting.approve'])->pluck('id')->all(),
        );
        $stranger = User::factory()->create(['role_id' => $strangerRole->id]);

        $this->actingAs($stranger, 'sanctum')
            ->patchJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}/acknowledge-budget")
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have permission to acknowledge this purchase order budget warning.');

        $finance = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'finance_officer')->value('id'),
        ]);
        $this->actingAs($finance, 'sanctum')
            ->patchJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}/acknowledge-budget")
            ->assertOk();
        $this->assertNotNull($po->fresh()->budget_acknowledged_at);
    }
}

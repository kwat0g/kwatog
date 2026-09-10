<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PS-01 — the seeded purchase_order chain is purchasing_officer →
 * finance_officer → system_admin, but finance_officer held no purchasing
 * permission and the row scope never matched chain participants, so every
 * submitted PO stalled at step 2: invisible on the approval queue and
 * unactionable through the approve route. Mirrors the M036 fix tests for the
 * purchase_request chain.
 */
class PurchaseOrderApprovalChainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    private function user(string $roleSlug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
        ]);
    }

    /**
     * A PO waiting on step 2: purchasing (step 1) already approved, the
     * finance_officer step pending. Only two records are seeded so the
     * finance approval is the final one and the PO must advance to Approved.
     */
    private function poPendingAtFinanceStep(): PurchaseOrder
    {
        $creator = $this->user('employee');
        $purchasing = $this->user('purchasing_officer');

        $po = PurchaseOrder::create([
            'po_number'    => 'PO-'.substr(uniqid(), -6),
            'vendor_id'    => \App\Modules\Accounting\Models\Vendor::create([
                'name'               => 'Vendor-'.substr(uniqid(), -5),
                'payment_terms_days' => 30,
            ])->id,
            'date'         => now()->toDateString(),
            'total_amount' => '10000.00',
            'created_by'   => $creator->id,
        ]);
        $po->forceFill(['status' => PurchaseOrderStatus::PendingApproval->value])->save();

        $attributes = [
            'approvable_type' => $po->getMorphClass(),
            'approvable_id'   => $po->id,
            'is_current'      => true,
            'approver_id'     => null,
            'acted_at'        => null,
            'created_at'      => now()->subHour(),
        ];
        DB::table('approval_records')->insert(array_merge($attributes, [
            'step_order'  => 1,
            'role_slug'   => 'purchasing_officer',
            'action'      => 'approved',
            'approver_id' => $purchasing->id,
            'acted_at'    => now()->subHour(),
        ]));
        DB::table('approval_records')->insert(array_merge($attributes, [
            'step_order' => 2,
            'role_slug'  => 'finance_officer',
            'action'     => 'pending',
        ]));

        return $po;
    }

    public function test_finance_officer_sees_the_pending_po_in_the_list(): void
    {
        $po = $this->poPendingAtFinanceStep();
        $finance = $this->user('finance_officer');

        $response = $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/purchasing/purchase-orders?per_page=100')
            ->assertOk();

        $numbers = array_map(static fn (array $row): string => (string) $row['po_number'], $response->json('data'));
        $this->assertContains($po->po_number, $numbers);
    }

    public function test_finance_officer_can_approve_step_two(): void
    {
        $po = $this->poPendingAtFinanceStep();
        $finance = $this->user('finance_officer');

        $this->actingAs($finance, 'sanctum')
            ->patchJson('/api/v1/purchasing/purchase-orders/'.$po->hash_id.'/approve', ['remarks' => 'ok'])
            ->assertOk();

        $step = DB::table('approval_records')
            ->where('approvable_type', $po->getMorphClass())
            ->where('approvable_id', $po->id)
            ->where('step_order', 2)
            ->first();
        $this->assertSame('approved', $step->action);
        $this->assertSame($finance->id, $step->approver_id);

        $fresh = $po->fresh();
        $this->assertSame(PurchaseOrderStatus::Approved, $fresh->status);
        $this->assertSame($finance->id, (int) $fresh->approved_by);
    }

    public function test_role_without_the_approve_permission_is_rejected(): void
    {
        $po = $this->poPendingAtFinanceStep();
        $warehouse = $this->user('warehouse_staff');

        $this->actingAs($warehouse, 'sanctum')
            ->patchJson('/api/v1/purchasing/purchase-orders/'.$po->hash_id.'/approve')
            ->assertForbidden();

        $this->assertSame('pending', DB::table('approval_records')
            ->where('approvable_type', $po->getMorphClass())
            ->where('approvable_id', $po->id)
            ->where('step_order', 2)
            ->value('action'));
    }

    public function test_approve_permission_holder_from_another_step_is_rejected(): void
    {
        $po = $this->poPendingAtFinanceStep();
        // purchasing_officer holds purchasing.po.approve but its step (1) is
        // already done; the pending step belongs to finance_officer alone.
        $purchasing = $this->user('purchasing_officer');

        $this->actingAs($purchasing, 'sanctum')
            ->patchJson('/api/v1/purchasing/purchase-orders/'.$po->hash_id.'/approve')
            ->assertForbidden();

        $this->assertSame('pending', DB::table('approval_records')
            ->where('approvable_type', $po->getMorphClass())
            ->where('approvable_id', $po->id)
            ->where('step_order', 2)
            ->value('action'));
    }
}

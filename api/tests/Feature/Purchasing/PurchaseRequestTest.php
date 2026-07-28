<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use App\Modules\Purchasing\Services\PurchaseRequestService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP-layer scaffold for Purchase Request CRUD and auth guards.
 *
 * Complements ApprovalWorkflowTest (service-layer) by exercising the actual
 * routes, middleware stack (auth:sanctum → feature:purchasing → permission:…),
 * and JSON response shape.
 *
 * Tests:
 *   1. Authenticated system_admin can create a PR via POST → 201, status=draft
 *   2. Unauthenticated request is rejected with 401
 *   3. Authenticated system_admin can submit a draft PR via PATCH → 200, status=pending
 *   4. A user whose role does not match the required approval step receives 403
 *      when attempting to approve (role-based approval guard)
 */
class PurchaseRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WorkflowSeeder::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeAdmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
            'email'   => 'admin+' . uniqid() . '@test.local',
        ]);
    }

    private function makeUserWithRole(string $roleSlug): User
    {
        $roleId = Role::where('slug', $roleSlug)->value('id');

        return User::factory()->create([
            'role_id' => $roleId,
            'email'   => $roleSlug . '+' . uniqid() . '@test.local',
        ]);
    }

    /** Minimal valid PR payload for the store endpoint. */
    private function validPayload(): array
    {
        return [
            'priority' => 'normal',
            'items'    => [
                [
                    'description' => 'A4 Bond Paper',
                    'quantity'    => '5',
                    'unit'        => 'ream',
                ],
            ],
        ];
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_authenticated_user_can_create_purchase_request(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)
            ->postJson('/api/v1/purchasing/purchase-requests', $this->validPayload());

        $response->assertStatus(201)
                 ->assertJsonPath('data.status', 'draft')
                 ->assertJsonStructure(['data' => ['id', 'pr_number', 'status', 'priority']]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/purchasing/purchase-requests', $this->validPayload());

        $response->assertUnauthorized();
    }

    public function test_create_requires_at_least_one_item(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)
            ->postJson('/api/v1/purchasing/purchase-requests', [
                'priority' => 'normal',
                'items'    => [],
            ]);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrorFor('items');
    }

    public function test_authenticated_user_can_submit_a_draft_pr(): void
    {
        $admin = $this->makeAdmin();

        // Create a draft PR via the service (bypasses HTTP to isolate the submit test).
        /** @var PurchaseRequestService $svc */
        $svc = app(PurchaseRequestService::class);
        $pr  = $svc->create($this->validPayload(), $admin);

        $this->assertSame(PurchaseRequestStatus::Draft, $pr->status);

        $response = $this->actingAs($admin)
            ->patchJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}/submit");

        $response->assertOk()
                 ->assertJsonPath('data.status', 'pending');
    }

    public function test_approve_endpoint_rejects_wrong_role(): void
    {
        $admin = $this->makeAdmin();

        // Create and submit a PR so it has pending approval records.
        /** @var PurchaseRequestService $svc */
        $svc    = app(PurchaseRequestService::class);
        $pr     = $svc->create($this->validPayload(), $admin);
        $svc->submit($pr);
        $pr->refresh();

        // A plain 'employee' role cannot act as 'department_head' (step 1).
        $employee = $this->makeUserWithRole('employee');

        $response = $this->actingAs($employee)
            ->patchJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}/approve");

        // The permission middleware will block an 'employee' who lacks
        // purchasing.pr.approve permission.
        $response->assertForbidden();
    }

    public function test_approved_pr_converts_with_hash_id_vendor_map_and_preserves_links(): void
    {
        $admin = $this->makeAdmin();
        $pr = PurchaseRequest::factory()->create();
        $pr->forceFill(['status' => PurchaseRequestStatus::Approved->value])->save();
        $item = Item::factory()->create();
        $line = PurchaseRequestItem::create([
            'purchase_request_id' => $pr->id,
            'item_id' => $item->id,
            'description' => 'Conversion test',
            'quantity' => '2.00',
            'unit' => 'pcs',
            'estimated_unit_price' => '25.00',
        ]);
        $vendor = Vendor::factory()->create();

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}/convert", [
                'vendor_map' => [$line->hash_id => $vendor->hash_id],
            ]);
        $response->assertCreated()
            ->assertJsonCount(1, 'data');

        $poId = app('hashids')->decode($response->json('data.0.id'))[0];
        $po = PurchaseOrder::with('items')->findOrFail($poId);
        $this->assertSame($pr->id, $po->purchase_request_id);
        $this->assertSame($vendor->id, $po->vendor_id);
        $this->assertSame($line->id, $po->items->first()->purchase_request_item_id);
        $this->assertSame('converted', $pr->fresh()->status->value);
    }

    public function test_conversion_rejects_missing_vendor_assignment_without_creating_po(): void
    {
        $admin = $this->makeAdmin();
        $pr = PurchaseRequest::factory()->create();
        $pr->forceFill(['status' => PurchaseRequestStatus::Approved->value])->save();
        $line = PurchaseRequestItem::create([
            'purchase_request_id' => $pr->id,
            'description' => 'Missing assignment',
            'quantity' => '1.00',
            'unit' => 'pcs',
            'estimated_unit_price' => '10.00',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}/convert", ['vendor_map' => []])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('purchase_orders', ['purchase_request_id' => $pr->id]);
        $this->assertSame('approved', $pr->fresh()->status->value);
        $this->assertNotNull($line->id);
    }
}

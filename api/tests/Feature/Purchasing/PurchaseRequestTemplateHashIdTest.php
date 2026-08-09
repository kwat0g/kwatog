<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestTemplate;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: the purchase-request flow must accept hash_ids.
 *
 * The SPA sends `template_id`/`department_id` as hash_ids (per the
 * ID-obfuscation rule). PR templates CRUD was HIDDEN 2026-08-08 (scope cut —
 * see SCOPE-CUT-AUDIT PASS 4), but the live purchase-request creation path that
 * consumes a template (`template_id` FK, purchase-request lines copied from the
 * template) stays. This pins the hash-id contract on that surviving path.
 */
class PurchaseRequestTemplateHashIdTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
        ]);
    }

    public function test_create_purchase_request_from_template_uses_hash_ids(): void
    {
        $department = Department::factory()->create();
        $template = PurchaseRequestTemplate::create([
            'name' => 'Monthly supplies',
            'department_id' => $department->id,
            'items' => [[
                'description' => 'A4 Bond Paper',
                'quantity' => '5',
                'unit' => 'ream',
            ]],
            'created_by' => $this->admin->id,
        ]);

        $otherDepartment = Department::factory()->create();

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/purchasing/purchase-requests', [
                'template_id' => $template->hash_id,
                'department_id' => $otherDepartment->hash_id,
                'items' => $template->items,
            ])
            ->assertCreated();

        $purchaseRequestId = PurchaseRequest::decodeHash($response->json('data.id'));
        $purchaseRequest = PurchaseRequest::findOrFail($purchaseRequestId);
        $this->assertSame($template->id, $purchaseRequest->template_id);
        $this->assertSame($otherDepartment->id, $purchaseRequest->department_id);
        $this->assertNotEmpty($purchaseRequest->items);
    }

    /** A template's own hash_id must round-trip through the shared decoder. */
    public function test_template_hash_id_decodes_to_same_row(): void
    {
        $department = Department::factory()->create();
        $template = PurchaseRequestTemplate::create([
            'name' => 'Storeroom restock',
            'department_id' => $department->id,
            'items' => [],
            'created_by' => $this->admin->id,
        ]);

        $this->assertSame(
            $template->id,
            PurchaseRequestTemplate::decodeHash($template->hash_id)
        );
    }
}

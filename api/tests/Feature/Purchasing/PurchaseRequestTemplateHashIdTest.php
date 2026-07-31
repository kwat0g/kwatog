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

    public function test_template_crud_and_use_template_contract_uses_hash_ids(): void
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

        $this->actingAs($this->admin)
            ->getJson('/api/v1/purchasing/pr-templates')
            ->assertOk()
            ->assertJsonPath('data.0.id', $template->hash_id)
            ->assertJsonPath('data.0.department.id', $department->hash_id);

        $this->actingAs($this->admin)
            ->getJson("/api/v1/purchasing/pr-templates/{$template->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.id', $template->hash_id);

        $otherDepartment = Department::factory()->create();
        $this->actingAs($this->admin)
            ->putJson("/api/v1/purchasing/pr-templates/{$template->hash_id}", [
                'department_id' => $otherDepartment->hash_id,
            ])
            ->assertOk()
            ->assertJsonPath('data.department.id', $otherDepartment->hash_id);

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
    }
}

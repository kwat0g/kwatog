<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PU-01 — show and pdf returned the full PO resource (vendor contacts, line
 * prices, GRNs, bills, approval chain) to ANY holder of purchasing.view,
 * bypassing PurchaseOrderAccessPolicy. Pin both read endpoints to the same
 * row ladder as the list:
 *
 *   - purchasing.po.approve / system_admin → every PO;
 *   - purchasing_officer (company-wide operator) → every PO;
 *   - department_head → own creations + POs whose linked PR is in their dept;
 *   - everyone else → own creations only.
 */
class PurchaseOrderShowScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithRole(string $slug, ?Department $department = null): User
    {
        $attributes = ['role_id' => Role::query()->where('slug', $slug)->value('id')];
        if ($department !== null) {
            $attributes['employee_id'] = Employee::factory()
                ->create(['department_id' => $department->id])->id;
        }

        return User::factory()->create($attributes);
    }

    private function poIn(?Department $department, ?int $createdBy = null): PurchaseOrder
    {
        return PurchaseOrder::factory()->create(array_filter([
            'created_by' => $createdBy,
            'purchase_request_id' => $department === null
                ? null
                : PurchaseRequest::factory()->create(['department_id' => $department->id])->id,
        ], static fn ($value) => $value !== null));
    }

    public function test_department_head_cannot_show_or_print_another_departments_po(): void
    {
        $own = Department::factory()->create();
        $other = Department::factory()->create();
        $head = $this->userWithRole('department_head', $own);
        $foreign = $this->poIn($other, User::factory()->create()->id);

        $this->actingAs($head, 'sanctum')
            ->getJson("/api/v1/purchasing/purchase-orders/{$foreign->hash_id}")
            ->assertForbidden();

        $this->actingAs($head, 'sanctum')
            ->get("/api/v1/purchasing/purchase-orders/{$foreign->hash_id}/pdf")
            ->assertForbidden();
    }

    public function test_department_head_can_show_and_print_own_department_pos(): void
    {
        $own = Department::factory()->create();
        $head = $this->userWithRole('department_head', $own);
        $mine = $this->poIn(null, $head->id);
        $colleague = $this->poIn($own, User::factory()->create()->id);

        foreach ([$mine, $colleague] as $po) {
            $this->actingAs($head, 'sanctum')
                ->getJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}")
                ->assertOk();

            $this->actingAs($head, 'sanctum')
                ->get("/api/v1/purchasing/purchase-orders/{$po->hash_id}/pdf")
                ->assertOk();
        }
    }

    public function test_purchasing_officer_can_show_and_print_any_po(): void
    {
        $department = Department::factory()->create();
        $officer = $this->userWithRole('purchasing_officer');
        $po = $this->poIn($department, User::factory()->create()->id);

        $this->actingAs($officer, 'sanctum')
            ->getJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}")
            ->assertOk();

        $this->actingAs($officer, 'sanctum')
            ->get("/api/v1/purchasing/purchase-orders/{$po->hash_id}/pdf")
            ->assertOk();
    }

    public function test_non_head_role_sees_list_scope_but_not_foreign_po_detail(): void
    {
        $department = Department::factory()->create();
        $role = Role::create([
            'name' => 'PO show scope '.substr(uniqid(), -5),
            'slug' => 'po_show_'.substr(uniqid(), -5),
            'is_system' => false,
        ]);
        $role->permissions()->sync(
            \App\Modules\Auth\Models\Permission::query()->where('slug', 'purchasing.view')->pluck('id')->all(),
        );
        $user = User::factory()->create([
            'role_id' => $role->id,
            'employee_id' => Employee::factory()->create(['department_id' => $department->id])->id,
        ]);

        $own = $this->poIn(null, $user->id);
        $foreign = $this->poIn($department, User::factory()->create()->id);

        $list = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/purchasing/purchase-orders?per_page=100')
            ->assertOk();
        $this->assertSame(
            [$own->po_number],
            array_map(static fn (array $row): string => (string) $row['po_number'], $list->json('data')),
        );

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/purchasing/purchase-orders/{$foreign->hash_id}")
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->get("/api/v1/purchasing/purchase-orders/{$foreign->hash_id}/pdf")
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/purchasing/purchase-orders/{$own->hash_id}")
            ->assertOk();
    }
}

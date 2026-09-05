<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Auth\Models\Permission;
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
 * Pins the purchase-order LIST row ladder now that it lives in
 * PurchaseOrderAccessPolicy (extracted verbatim from PurchaseOrderService::list
 * on 2026-09 so global search and the approval board share one source of
 * truth):
 *
 *   - purchasing.po.approve / system_admin → every PO;
 *   - purchasing_officer (company-wide operator) → every PO;
 *   - department_head → own creations + POs whose linked PR is in their dept;
 *   - everyone else → own creations only.
 */
class PurchaseOrderListScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /** @return array<int, string> */
    private function poNumbers(User $user): array
    {
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/purchasing/purchase-orders?per_page=100')
            ->assertOk();

        return array_map(
            static fn (array $row): string => (string) $row['po_number'],
            $response->json('data'),
        );
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

    public function test_purchasing_officers_see_every_po(): void
    {
        $alpha = Department::factory()->create();
        $beta = Department::factory()->create();
        $this->poIn($alpha);
        $this->poIn($beta);

        $officer = $this->userWithRole('purchasing_officer');

        $this->assertCount(2, $this->poNumbers($officer));
    }

    public function test_department_head_sees_own_and_own_department_pos_only(): void
    {
        $own = Department::factory()->create();
        $other = Department::factory()->create();
        $head = $this->userWithRole('department_head', $own);

        $mine = $this->poIn($own);
        $colleague = $this->poIn($own, User::factory()->create()->id);
        $foreign = $this->poIn($other);

        $numbers = $this->poNumbers($head);

        $this->assertContains($mine->po_number, $numbers);
        $this->assertContains($colleague->po_number, $numbers);
        $this->assertNotContains($foreign->po_number, $numbers);
    }

    public function test_other_roles_see_only_their_own_pos_even_with_a_department(): void
    {
        $department = Department::factory()->create();
        $role = Role::create([
            'name' => 'PO scope custom '.substr(uniqid(), -5),
            'slug' => 'po_scope_'.substr(uniqid(), -5),
            'is_system' => false,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('slug', ['purchasing.view'])->pluck('id')->all(),
        );
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee->id]);

        $own = $this->poIn(null, $user->id);
        $this->poIn($department, User::factory()->create()->id);

        $this->assertSame([$own->po_number], $this->poNumbers($user));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_own_payslip_permission_does_not_expose_company_payroll_periods(): void
    {
        $user = $this->userWithPermissions(['payroll.view']);

        $this->actingAs($user)
            ->getJson('/api/v1/payroll-periods')
            ->assertForbidden();
    }

    public function test_payroll_period_viewer_can_list_periods(): void
    {
        $user = $this->userWithPermissions(['payroll.periods.view']);

        $this->actingAs($user)
            ->getJson('/api/v1/payroll-periods')
            ->assertOk();
    }

    public function test_own_payslip_permission_does_not_expose_de_minimis_records(): void
    {
        $user = $this->userWithPermissions(['payroll.view']);

        $this->actingAs($user)
            ->getJson('/api/v1/de-minimis')
            ->assertForbidden();
    }

    /** @param array<int, string> $slugs */
    private function userWithPermissions(array $slugs): User
    {
        $role = Role::create([
            'slug' => 'payroll-auth-'.uniqid(),
            'name' => 'Payroll authorization test',
            'description' => 'Test role',
        ]);

        foreach ($slugs as $slug) {
            $permission = Permission::create([
                'slug' => $slug,
                'name' => $slug,
                'module' => 'payroll',
            ]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }
}

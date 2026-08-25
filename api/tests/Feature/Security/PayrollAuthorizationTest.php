<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Models\PayrollAdjustment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_adjustment_viewer_can_read_but_cannot_approve_or_reject(): void
    {
        $user = $this->userWithPermissions(['payroll.adjustments.view']);
        $adjustmentId = $this->pendingAdjustmentId();

        $this->actingAs($user)
            ->getJson('/api/v1/payroll-adjustments/options')
            ->assertOk();

        $this->actingAs($user)
            ->patchJson("/api/v1/payroll-adjustments/{$adjustmentId}/approve")
            ->assertForbidden();

        $this->actingAs($user)
            ->patchJson("/api/v1/payroll-adjustments/{$adjustmentId}/reject", ['remarks' => 'No'])
            ->assertForbidden();
    }

    public function test_adjustment_creator_cannot_read_or_approve_without_checker_permissions(): void
    {
        $user = $this->userWithPermissions(['payroll.adjustments.create']);
        $adjustmentId = $this->pendingAdjustmentId();

        $this->actingAs($user)
            ->getJson('/api/v1/payroll-adjustments')
            ->assertForbidden();

        $this->actingAs($user)
            ->patchJson("/api/v1/payroll-adjustments/{$adjustmentId}/approve")
            ->assertForbidden();
    }

    private function pendingAdjustmentId(): string
    {
        $employee = Employee::factory()->create();
        $id = DB::table('payroll_adjustments')->insertGetId([
            'employee_id' => $employee->id,
            'type' => 'underpayment',
            'amount' => '1.00',
            'reason' => 'Authorization test',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return PayrollAdjustment::query()->findOrFail($id)->hash_id;
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

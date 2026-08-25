<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayslipPublicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_payroll_list_only_returns_finalized_or_disbursed_rows_without_errors(): void
    {
        $employee = Employee::factory()->create();
        $user = $this->userWithPermissions(['payroll.view'], $employee->id);

        foreach (['draft', 'processing', 'computed', 'approved', 'voided'] as $status) {
            $this->payroll($employee, $status);
        }
        $this->payroll($employee, 'finalized');
        $this->payroll($employee, 'disbursed');
        $this->payroll($employee, 'finalized', 'Payroll computation failed.');

        $response = $this->actingAs($user)->getJson('/api/v1/payrolls');

        $response->assertOk()->assertJsonCount(2, 'data');
        $statuses = collect($response->json('data'))->pluck('period_status')->sort()->values()->all();
        $this->assertSame(['disbursed', 'finalized'], $statuses);
    }

    public function test_direct_payslip_route_rejects_unpublished_and_error_rows(): void
    {
        $employee = Employee::factory()->create();
        $user = $this->userWithPermissions(['payroll.view'], $employee->id);
        $draft = $this->payroll($employee, 'draft');
        $error = $this->payroll($employee, 'finalized', 'Missing shift assignment.');

        $this->actingAs($user)
            ->getJson("/api/v1/payrolls/{$draft->hash_id}/payslip")
            ->assertStatus(422);
        $this->actingAs($user)
            ->getJson("/api/v1/payrolls/{$error->hash_id}/payslip")
            ->assertStatus(422);
    }

    public function test_self_service_certificate_does_not_render_from_a_draft_period(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->create(['employee_id' => $employee->id]);
        $period = PayrollPeriod::factory()->create([
            'period_start' => now()->startOfYear()->toDateString(),
            'period_end' => now()->startOfYear()->addDays(14)->toDateString(),
        ]);
        $period->forceFill(['status' => 'draft'])->saveQuietly();
        Payroll::factory()->create([
            'employee_id' => $employee->id,
            'payroll_period_id' => $period->id,
        ]);

        $this->actingAs($user)
            ->get("/api/v1/hr/self-service/documents/contributions/sss?year=".now()->year)
            ->assertNotFound();
        $this->actingAs($user)
            ->get("/api/v1/hr/self-service/documents/bir-2316?year=".now()->year)
            ->assertNotFound();
    }

    /** @param array<int, string> $permissions */
    private function userWithPermissions(array $permissions, int $employeeId): User
    {
        $role = Role::create([
            'slug' => 'payslip-publication-'.uniqid(),
            'name' => 'Payslip publication test',
            'description' => 'Payslip publication test role',
        ]);

        foreach ($permissions as $slug) {
            $permission = Permission::create([
                'slug' => $slug,
                'name' => $slug,
                'module' => 'payroll',
            ]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create([
            'role_id' => $role->id,
            'employee_id' => $employeeId,
        ]);
    }

    private function payroll(Employee $employee, string $status, ?string $error = null): Payroll
    {
        $period = PayrollPeriod::factory()->create();
        $period->forceFill(['status' => $status])->saveQuietly();

        return Payroll::factory()->create([
            'employee_id' => $employee->id,
            'payroll_period_id' => $period->id,
            'error_message' => $error,
        ]);
    }
}
